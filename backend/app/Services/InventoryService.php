<?php

namespace App\Services;

use App\Models\InventoryBatch;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\ReturnNote;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    public function receivePurchase(Purchase $purchase): void
    {
        foreach ($purchase->items()->with('product')->lockForUpdate()->get() as $item) {
            $this->receivePurchaseItem($purchase, $item);
        }
    }

    public function receivePurchaseItem(Purchase $purchase, PurchaseItem $item): void
    {
        $product = $item->product;
        if (! $product) {
            return;
        }

        $product = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
        $product->update(['purchase_price' => $item->unit_rate]);
        if (! $product->track_stock || $product->is_service) {
            return;
        }
        $unitCostTotal = $purchase->claim_input_tax_credit
            ? (float) $item->taxable_amount
            : (float) $item->line_total;
        $unitCost = round($unitCostTotal / (float) $item->quantity, 4);
        $batch = InventoryBatch::create([
            'user_id' => $purchase->user_id,
            'business_id' => $purchase->business_id,
            'product_id' => $product->id,
            'purchase_id' => $purchase->id,
            'purchase_item_id' => $item->id,
            'batch_number' => $item->batch_number,
            'received_date' => $purchase->purchase_date,
            'expiry_date' => $item->expiry_date,
            'quantity_received' => $item->quantity,
            'quantity_remaining' => $item->quantity,
            'unit_cost' => $unitCost,
        ]);
        $product->increment('current_stock', (float) $item->quantity);
        $this->movement($purchase, $product, $batch, 'purchase', 'purchase', $purchase->id, (float) $item->quantity, $unitCost, null, $item->id);
    }

    public function consumeSale(Sale $sale): float
    {
        $costTotal = 0.0;
        foreach ($sale->items()->with('product')->lockForUpdate()->get() as $item) {
            $product = $item->product;
            if (! $product) {
                continue;
            }

            $product = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            if (! $product->track_stock || $product->is_service) {
                continue;
            }
            $itemCost = $this->consume(
                $sale->user_id,
                $sale->business_id,
                $product,
                (float) $item->quantity,
                'sale',
                $sale->id,
                $item->id
            );
            $costTotal += $itemCost;
            $item->update(['inventory_cost' => $itemCost]);
        }

        return round($costTotal, 2);
    }

    public function reverseSale(Sale $sale): void
    {
        $movements = InventoryMovement::query()
            ->where('business_id', $sale->business_id)
            ->where('source_type', 'sale')
            ->where('source_id', $sale->id)
            ->where('quantity_delta', '<', 0)
            ->whereNull('reversed_at')
            ->with(['product', 'batch'])
            ->lockForUpdate()
            ->get();

        foreach ($movements as $movement) {
            if (! $movement->product || ! $movement->batch) {
                throw ValidationException::withMessages([
                    'sale' => ['This sale has incomplete inventory history and cannot be changed.'],
                ]);
            }
            $quantity = abs((float) $movement->quantity_delta);
            if ($movement->product->trashed()) {
                $movement->product->restore();
            }
            $movement->batch->increment('quantity_remaining', $quantity);
            $movement->product->increment('current_stock', $quantity);
            $this->movement(
                $sale,
                $movement->product,
                $movement->batch,
                'sale_reversal',
                'sale_reversal',
                $sale->id,
                $quantity,
                (float) $movement->unit_cost,
                $movement->sale_item_id
            );
            $movement->update(['reversed_at' => now()]);
        }
    }

    public function reversePurchase(Purchase $purchase): void
    {
        $batches = InventoryBatch::query()
            ->where('business_id', $purchase->business_id)
            ->where('purchase_id', $purchase->id)
            ->whereNull('reversed_at')
            ->with('product')
            ->lockForUpdate()
            ->get();

        foreach ($batches as $batch) {
            $received = (float) $batch->quantity_received;
            $remaining = (float) $batch->quantity_remaining;
            if (round($received - $remaining, 3) !== 0.0) {
                throw ValidationException::withMessages([
                    'purchase' => ['This purchase has stock already used in sales. Return that stock before editing or deleting the purchase.'],
                ]);
            }

            $batch->update(['quantity_remaining' => 0]);
            if ($batch->product) {
                $batch->product->decrement('current_stock', $remaining);
                $this->movement(
                    $purchase,
                    $batch->product,
                    $batch,
                    'purchase_reversal',
                    'purchase_reversal',
                    $purchase->id,
                    -$remaining,
                    (float) $batch->unit_cost,
                    null,
                    $batch->purchase_item_id
                );
            }
            $batch->update(['reversed_at' => now()]);
        }
    }

    private function consume(
        int $userId,
        int $businessId,
        Product $product,
        float $quantity,
        string $movementType,
        int $sourceId,
        int $saleItemId
    ): float {
        $remaining = $quantity;
        $cost = 0.0;
        $batches = $product->batches()
            ->where('quantity_remaining', '>', 0)
            ->where(function ($query): void {
                $query->whereNull('expiry_date')->orWhereDate('expiry_date', '>=', now()->toDateString());
            })
            ->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('expiry_date')
            ->orderBy('received_date')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }
            $used = min($remaining, (float) $batch->quantity_remaining);
            $batch->decrement('quantity_remaining', $used);
            $product->decrement('current_stock', $used);
            $cost += $used * (float) $batch->unit_cost;
            $this->writeMovement(
                $userId,
                $businessId,
                $product,
                $batch,
                $movementType,
                $movementType,
                $sourceId,
                -$used,
                (float) $batch->unit_cost,
                $saleItemId
            );
            $remaining = round($remaining - $used, 3);
        }

        if ($remaining > 0) {
            throw ValidationException::withMessages([
                'items' => ["Insufficient stock for {$product->name}; {$remaining} more unit(s) are required."],
            ]);
        }

        return round($cost, 2);
    }

    private function movement(
        Purchase|Sale $source,
        Product $product,
        InventoryBatch $batch,
        string $type,
        string $sourceType,
        int $sourceId,
        float $quantityDelta,
        float $unitCost,
        ?int $saleItemId = null,
        ?int $purchaseItemId = null
    ): InventoryMovement {
        return InventoryMovement::create([
            'user_id' => $source->user_id,
            'business_id' => $source->business_id,
            'product_id' => $product->id,
            'batch_id' => $batch->id,
            'sale_item_id' => $saleItemId,
            'purchase_item_id' => $purchaseItemId,
            'movement_type' => $type,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'quantity_delta' => $quantityDelta,
            'unit_cost' => $unitCost,
        ]);
    }

    public function returnSale(Sale $sale, SaleItem $saleItem, float $quantity, int $noteId): float
    {
        $movements = InventoryMovement::query()
            ->where('business_id', $sale->business_id)
            ->where('source_type', 'sale')
            ->where('source_id', $sale->id)
            ->where('sale_item_id', $saleItem->id)
            ->where('quantity_delta', '<', 0)
            ->whereNull('reversed_at')
            ->with(['product', 'batch'])
            ->orderByDesc('id')
            ->lockForUpdate()
            ->get();
        $returnedByBatch = InventoryMovement::query()
            ->where('business_id', $sale->business_id)
            ->where('source_type', 'credit_note')
            ->where('sale_item_id', $saleItem->id)
            ->where('quantity_delta', '>', 0)
            ->whereNull('reversed_at')
            ->selectRaw('batch_id, SUM(quantity_delta) as returned_quantity')
            ->groupBy('batch_id')
            ->pluck('returned_quantity', 'batch_id');

        $remaining = $quantity;
        $costTotal = 0.0;
        foreach ($movements as $movement) {
            if (! $movement->batch || ! $movement->product) {
                continue;
            }
            $sold = abs((float) $movement->quantity_delta);
            $alreadyReturned = (float) ($returnedByBatch[$movement->batch_id] ?? 0);
            $available = max(0, $sold - $alreadyReturned);
            $returned = min($remaining, $available);
            if ($returned <= 0) {
                continue;
            }
            $movement->batch->increment('quantity_remaining', $returned);
            if ($movement->product->trashed()) {
                $movement->product->restore();
            }
            $movement->product->increment('current_stock', $returned);
            $this->writeMovement(
                $sale->user_id,
                $sale->business_id,
                $movement->product,
                $movement->batch,
                'credit_note',
                'credit_note',
                $noteId,
                $returned,
                (float) $movement->unit_cost,
                $saleItem->id
            );
            $costTotal += $returned * (float) $movement->unit_cost;
            $remaining = round($remaining - $returned, 3);
            if ($remaining <= 0) {
                break;
            }
        }

        if ($remaining > 0) {
            throw ValidationException::withMessages([
                'items' => ["Only ".round($quantity - $remaining, 3)." unit(s) from this sale are available to return to stock."],
            ]);
        }

        return round($costTotal, 2);
    }

    public function returnPurchase(Purchase $purchase, PurchaseItem $purchaseItem, float $quantity, int $noteId): float
    {
        $batch = InventoryBatch::query()
            ->where('business_id', $purchase->business_id)
            ->where('purchase_item_id', $purchaseItem->id)
            ->whereNull('reversed_at')
            ->lockForUpdate()
            ->first();
        if (! $batch || (float) $batch->quantity_remaining < $quantity) {
            throw ValidationException::withMessages([
                'items' => ['This purchase does not have enough remaining stock for the requested return.'],
            ]);
        }

        $batch->decrement('quantity_remaining', $quantity);
        $product = Product::query()->whereKey($batch->product_id)->lockForUpdate()->firstOrFail();
        $product->decrement('current_stock', $quantity);
        $this->writeMovement(
            $purchase->user_id,
            $purchase->business_id,
            $product,
            $batch,
            'debit_note',
            'debit_note',
            $noteId,
            -$quantity,
            (float) $batch->unit_cost,
            null,
            $purchaseItem->id
        );

        return round($quantity * (float) $batch->unit_cost, 2);
    }

    public function reverseReturnNote(ReturnNote $note): void
    {
        $movements = InventoryMovement::query()
            ->where('business_id', $note->business_id)
            ->where('source_type', $note->note_type.'_note')
            ->where('source_id', $note->id)
            ->whereNull('reversed_at')
            ->whereNotNull('batch_id')
            ->with(['product', 'batch'])
            ->lockForUpdate()
            ->get();

        foreach ($movements as $movement) {
            if (! $movement->product || ! $movement->batch) {
                throw ValidationException::withMessages([
                    'note' => ['This note has incomplete inventory history and cannot be changed.'],
                ]);
            }

            $product = Product::withTrashed()->lockForUpdate()->findOrFail($movement->product_id);
            $batch = InventoryBatch::query()->lockForUpdate()->findOrFail($movement->batch_id);
            $delta = (float) $movement->quantity_delta;
            $quantity = abs($delta);

            if ($note->note_type === 'credit') {
                if ($delta <= 0) {
                    throw ValidationException::withMessages([
                        'note' => ['This credit note has inconsistent inventory history and cannot be changed.'],
                    ]);
                }
                if ((float) $batch->quantity_remaining < $quantity || (float) $product->current_stock < $quantity) {
                    throw ValidationException::withMessages([
                        'note' => ['Some stock returned by this credit note has since been sold or adjusted, so it cannot be edited or deleted.'],
                    ]);
                }
                $batch->decrement('quantity_remaining', $quantity);
                $product->decrement('current_stock', $quantity);
                $delta = -$quantity;
            } else {
                if ($delta >= 0 || $batch->reversed_at) {
                    throw ValidationException::withMessages([
                        'note' => ['This debit note has inconsistent inventory history and cannot be changed.'],
                    ]);
                }
                if ($product->trashed()) {
                    $product->restore();
                }
                $batch->increment('quantity_remaining', $quantity);
                $product->increment('current_stock', $quantity);
                $delta = $quantity;
            }

            $this->writeMovement(
                $note->user_id,
                $note->business_id,
                $product,
                $batch,
                $note->note_type.'_note_reversal',
                $note->note_type.'_note_reversal',
                $note->id,
                $delta,
                (float) $movement->unit_cost,
                $movement->sale_item_id,
                $movement->purchase_item_id
            );
            $movement->update(['reversed_at' => now()]);
        }
    }

    private function writeMovement(
        int $userId,
        int $businessId,
        Product $product,
        InventoryBatch $batch,
        string $type,
        string $sourceType,
        int $sourceId,
        float $quantityDelta,
        float $unitCost,
        ?int $saleItemId = null,
        ?int $purchaseItemId = null
    ): InventoryMovement {
        return InventoryMovement::create([
            'user_id' => $userId,
            'business_id' => $businessId,
            'product_id' => $product->id,
            'batch_id' => $batch->id,
            'sale_item_id' => $saleItemId,
            'purchase_item_id' => $purchaseItemId,
            'movement_type' => $type,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'quantity_delta' => $quantityDelta,
            'unit_cost' => $unitCost,
        ]);
    }
}
