<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryBatch;
use App\Models\InventoryMovement;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\ProductCategory;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use LogicException;

class InventoryController extends Controller
{
    private const GST_RATES = [0.0, 0.25, 3.0, 5.0, 12.0, 18.0, 28.0, 40.0];

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $user = $request->user();
        $businessId = $user->activeBusiness->id;

        $query = Product::query()
            ->where('user_id', $user->id)
            ->where('business_id', $businessId)
            ->with('category:id,name')
            ->when($validated['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%")
                        ->orWhere('barcode', 'like', "%{$search}%")
                        ->orWhere('hsn_sac', 'like', "%{$search}%");
                });
            })
            ->when($validated['category_id'] ?? null, fn (Builder $query, int $id) => $query->where('category_id', $id));

        $products = (clone $query)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 50)
            ->withQueryString();

        return response()->json([
            'data' => $products->items(),
            'meta' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'total' => $products->total(),
            ],
            'categories' => ProductCategory::query()
                ->where('user_id', $user->id)
                ->where('business_id', $businessId)
                ->withCount('products')
                ->orderBy('name')
                ->get(['id', 'name']),
            'summary' => [
                'products' => (clone $query)->count(),
                'low_stock' => Product::query()
                    ->where('user_id', $user->id)
                    ->where('business_id', $businessId)
                    ->where('track_stock', true)
                    ->whereNotNull('low_stock_threshold')
                    ->whereColumn('current_stock', '<=', 'low_stock_threshold')
                    ->count(),
                'stock_value' => round((float) InventoryBatch::query()
                    ->where('inventory_batches.business_id', $businessId)
                    ->join('products', 'products.id', '=', 'inventory_batches.product_id')
                    ->whereNull('products.deleted_at')
                    ->sum(DB::raw('inventory_batches.quantity_remaining * inventory_batches.unit_cost')), 2),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->normalizeIdentifiers($request);
        $validated = $request->validate($this->productRules($request));
        $this->validateGst($validated['gst_rate']);
        $this->validateProductOptions($validated);
        $user = $request->user();
        $businessId = $user->activeBusiness->id;
        $this->assertCategory($user->id, $businessId, $validated['category_id'] ?? null);

        $product = DB::transaction(function () use ($validated, $user, $businessId): Product {
            $openingQuantity = (float) ($validated['opening_quantity'] ?? 0);
            $openingCost = round((float) ($validated['opening_unit_cost'] ?? 0), 4);
            $trackStock = (bool) ($validated['track_stock'] ?? true) && ! (bool) ($validated['is_service'] ?? false);

            if ($openingQuantity > 0 && ! $trackStock) {
                throw ValidationException::withMessages([
                    'opening_quantity' => ['Opening stock requires a product that tracks stock.'],
                ]);
            }

            $product = Product::create([
                'user_id' => $user->id,
                'business_id' => $businessId,
                'category_id' => $validated['category_id'] ?? null,
                'name' => $validated['name'],
                'sku' => $validated['sku'] ?? null,
                'barcode' => $validated['barcode'] ?? null,
                'hsn_sac' => $validated['hsn_sac'] ?? null,
                'unit_code' => $validated['unit_code'] ?? null,
                'gst_rate' => $validated['gst_rate'],
                'purchase_price' => $validated['purchase_price'] ?? 0,
                'selling_price' => $validated['selling_price'] ?? 0,
                'is_service' => $validated['is_service'] ?? false,
                'track_stock' => $trackStock,
                'batch_tracking' => $validated['batch_tracking'] ?? false,
                'low_stock_threshold' => $validated['low_stock_threshold'] ?? null,
                'current_stock' => $openingQuantity,
            ]);

            if ($openingQuantity > 0) {
                $batch = InventoryBatch::create([
                    'user_id' => $user->id,
                    'business_id' => $businessId,
                    'product_id' => $product->id,
                    'batch_number' => $validated['opening_batch_number'] ?? null,
                    'received_date' => now()->toDateString(),
                    'expiry_date' => $validated['opening_expiry_date'] ?? null,
                    'quantity_received' => $openingQuantity,
                    'quantity_remaining' => $openingQuantity,
                    'unit_cost' => $openingCost,
                ]);
                $this->movement($user->id, $businessId, $product, $batch, 'opening', null, null, $openingQuantity, $openingCost);
                $this->postAdjustmentJournal(
                    $user->id,
                    $businessId,
                    $product->id,
                    'inventory_opening',
                    $product->id,
                    round($openingQuantity * $openingCost, 2),
                    'Opening stock for '.$product->name,
                    true
                );
            }

            return $product->fresh('category');
        }, 3);

        return response()->json($product, 201);
    }

    public function update(Request $request, int $product): JsonResponse
    {
        $record = $this->ownedProduct($request, $product);
        $this->normalizeIdentifiers($request);
        $validated = $request->validate($this->productRules($request, $record->id));
        $this->validateGst($validated['gst_rate']);
        $this->validateProductOptions($validated);
        $this->assertCategory($request->user()->id, $record->business_id, $validated['category_id'] ?? null);

        if ($record->current_stock > 0 && (
            ! ($validated['track_stock'] ?? true)
            || ($validated['is_service'] ?? false)
        )) {
            throw ValidationException::withMessages([
                'track_stock' => ['Stock must be zero before stock tracking can be disabled.'],
            ]);
        }

        $record->update([
            'category_id' => $validated['category_id'] ?? null,
            'name' => $validated['name'],
            'sku' => $validated['sku'] ?? null,
            'barcode' => $validated['barcode'] ?? null,
            'hsn_sac' => $validated['hsn_sac'] ?? null,
            'unit_code' => $validated['unit_code'] ?? null,
            'gst_rate' => $validated['gst_rate'],
            'purchase_price' => $validated['purchase_price'] ?? 0,
            'selling_price' => $validated['selling_price'] ?? 0,
            'is_service' => $validated['is_service'] ?? false,
            'track_stock' => (bool) ($validated['track_stock'] ?? true) && ! (bool) ($validated['is_service'] ?? false),
            'batch_tracking' => $validated['batch_tracking'] ?? false,
            'low_stock_threshold' => $validated['low_stock_threshold'] ?? null,
        ]);

        return response()->json($record->fresh('category'));
    }

    public function destroy(Request $request, int $product): JsonResponse
    {
        $record = $this->ownedProduct($request, $product);
        if ((float) $record->current_stock !== 0.0) {
            throw ValidationException::withMessages([
                'product' => ['Adjust stock to zero before deleting this product.'],
            ]);
        }

        $record->delete();

        return response()->json(null, 204);
    }

    public function categories(Request $request): JsonResponse
    {
        $categories = ProductCategory::query()
            ->where('user_id', $request->user()->id)
            ->where('business_id', $request->user()->activeBusiness->id)
            ->withCount('products')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $categories]);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        if (is_string($request->input('name'))) {
            $request->merge(['name' => $this->formatCategoryName($request->input('name'))]);
        }
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:80',
                Rule::unique('product_categories', 'name')->where(
                    fn (QueryBuilder $query) => $query->where('business_id', $request->user()->activeBusiness->id)
                ),
            ],
        ]);
        $businessId = $request->user()->activeBusiness->id;
        if (ProductCategory::query()
            ->where('business_id', $businessId)
            ->whereRaw('LOWER(name) = LOWER(?)', [$validated['name']])
            ->exists()) {
            throw ValidationException::withMessages([
                'name' => ['This category already exists for this business.'],
            ]);
        }
        $user = $request->user();
        $category = ProductCategory::create([
            'user_id' => $user->id,
            'business_id' => $businessId,
            'name' => $this->formatCategoryName($validated['name']),
        ]);

        return response()->json($category, 201);
    }

    private function formatCategoryName(string $name): string
    {
        return mb_convert_case(trim(preg_replace('/\s+/', ' ', $name)), MB_CASE_TITLE, 'UTF-8');
    }

    public function destroyCategory(Request $request, int $category): JsonResponse
    {
        $record = ProductCategory::query()
            ->where('user_id', $request->user()->id)
            ->where('business_id', $request->user()->activeBusiness->id)
            ->findOrFail($category);
        $record->products()->update(['category_id' => null]);
        $record->delete();

        return response()->json(null, 204);
    }

    public function reorder(Request $request): JsonResponse
    {
        $user = $request->user();
        $businessId = $user->activeBusiness->id;
        $sold = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.business_id', $businessId)
            ->whereNull('sales.deleted_at')
            ->where('sales.sale_date', '>=', now()->subDays(30)->toDateString())
            ->select('sale_items.product_id', DB::raw('SUM(sale_items.quantity) as sold_quantity'))
            ->groupBy('sale_items.product_id');
        $products = Product::query()
            ->where('products.user_id', $user->id)
            ->where('products.business_id', $businessId)
            ->where('products.track_stock', true)
            ->whereNotNull('products.low_stock_threshold')
            ->whereColumn('products.current_stock', '<=', 'products.low_stock_threshold')
            ->leftJoinSub($sold, 'recent_sales', 'recent_sales.product_id', '=', 'products.id')
            ->with('category:id,name')
            ->get([
                'products.*',
                DB::raw('COALESCE(recent_sales.sold_quantity, 0) as sold_last_30_days'),
            ])
            ->map(function (Product $product) use ($businessId): array {
                $supplierName = DB::table('purchase_items')
                    ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
                    ->where('purchase_items.product_id', $product->id)
                    ->where('purchases.business_id', $businessId)
                    ->whereNull('purchases.deleted_at')
                    ->orderByDesc('purchases.purchase_date')
                    ->value('purchases.supplier_name_snapshot');
                $target = max((float) $product->low_stock_threshold * 2, (float) $product->sold_last_30_days);

                return [
                    ...$product->toArray(),
                    'last_supplier' => $supplierName,
                    'suggested_order_quantity' => max(0, round($target - (float) $product->current_stock, 3)),
                ];
            });

        return response()->json(['data' => $products]);
    }

    public function expiring(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'days' => ['nullable', 'integer', Rule::in([30, 60, 90])],
        ]);
        $days = (int) ($validated['days'] ?? 30);
        $today = now()->toDateString();
        $batches = InventoryBatch::query()
            ->with('product:id,name,sku,unit_code')
            ->where('business_id', $request->user()->activeBusiness->id)
            ->where('quantity_remaining', '>', 0)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '>=', $today)
            ->whereDate('expiry_date', '<=', Carbon::parse($today)->addDays($days)->toDateString())
            ->orderBy('expiry_date')
            ->get();

        return response()->json(['data' => $batches, 'days' => $days]);
    }

    public function adjustStock(Request $request, int $product): JsonResponse
    {
        $validated = $request->validate([
            'counted_quantity' => ['required', 'numeric', 'min:0', 'max:999999999.999', 'decimal:0,3'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);
        $user = $request->user();
        $businessId = $user->activeBusiness->id;

        $record = DB::transaction(function () use ($request, $product, $validated, $user, $businessId): Product {
            $record = Product::query()
                ->where('user_id', $user->id)
                ->where('business_id', $businessId)
                ->lockForUpdate()
                ->findOrFail($product);
            if (! $record->track_stock) {
                throw ValidationException::withMessages(['product' => ['This product does not track stock.']]);
            }

            $current = (float) $record->current_stock;
            $counted = (float) $validated['counted_quantity'];
            $delta = round($counted - $current, 3);
            if ($delta === 0.0) {
                return $record;
            }

            $cost = $this->averageUnitCost($record);
            $sourceId = $record->id;
            if ($delta < 0) {
                $this->consumeStock($record, abs($delta), 'stock_adjustment', $sourceId);
            } else {
                $batch = InventoryBatch::create([
                    'user_id' => $user->id,
                    'business_id' => $businessId,
                    'product_id' => $record->id,
                    'batch_number' => null,
                    'received_date' => now()->toDateString(),
                    'quantity_received' => $delta,
                    'quantity_remaining' => $delta,
                    'unit_cost' => $cost,
                ]);
                $this->movement($user->id, $businessId, $record, $batch, 'adjustment', 'stock_adjustment', $sourceId, $delta, $cost, $validated['notes'] ?? null);
            }

            $record->update(['current_stock' => $counted]);
            $this->postAdjustmentJournal(
                $user->id,
                $businessId,
                $record->id,
                'stock_adjustment',
                $sourceId,
                round(abs($delta) * $cost, 2),
                $validated['notes'] ?? 'Stock count adjustment',
                $delta > 0
            );

            return $record->fresh();
        }, 3);

        return response()->json($record);
    }

    private function productRules(Request $request, ?int $ignoreId = null): array
    {
        $businessId = $request->user()->activeBusiness->id;
        $unique = static function (string $column) use ($businessId, $ignoreId) {
            $rule = Rule::unique('products', $column)
                ->where(fn (QueryBuilder $query) => $query->where('business_id', $businessId));
            return $ignoreId ? $rule->ignore($ignoreId) : $rule;
        };

        return [
            'category_id' => ['nullable', 'integer'],
            'name' => ['required', 'string', 'max:180'],
            'sku' => ['nullable', 'string', 'max:60', $unique('sku')],
            'barcode' => ['nullable', 'string', 'max:80', $unique('barcode')],
            'hsn_sac' => ['nullable', 'string', 'max:20'],
            'unit_code' => ['nullable', 'string', 'max:8'],
            'gst_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'purchase_price' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'selling_price' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'is_service' => ['sometimes', 'boolean'],
            'track_stock' => ['sometimes', 'boolean'],
            'batch_tracking' => ['sometimes', 'boolean'],
            'low_stock_threshold' => ['nullable', 'numeric', 'min:0', 'max:999999999.999'],
            'opening_quantity' => ['sometimes', 'numeric', 'min:0', 'max:999999999.999', 'decimal:0,3'],
            'opening_unit_cost' => ['required_with:opening_quantity', 'numeric', 'min:0', 'max:999999999999.99'],
            'opening_batch_number' => ['nullable', 'string', 'max:80'],
            'opening_expiry_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
        ];
    }

    private function validateGst(float|int|string $rate): void
    {
        if (! in_array(round((float) $rate, 2), self::GST_RATES, true)) {
            throw ValidationException::withMessages([
                'gst_rate' => ['Choose a supported GST rate: 0%, 0.25%, 3%, 5%, 12%, 18%, 28% or 40%.'],
            ]);
        }
    }

    private function validateProductOptions(array $validated): void
    {
        $isService = (bool) ($validated['is_service'] ?? false);
        $trackStock = (bool) ($validated['track_stock'] ?? true) && ! $isService;
        $batchTracking = (bool) ($validated['batch_tracking'] ?? false);
        $openingQuantity = (float) ($validated['opening_quantity'] ?? 0);

        if (! $trackStock && $openingQuantity > 0) {
            throw ValidationException::withMessages([
                'opening_quantity' => ['Opening stock requires a product that tracks stock.'],
            ]);
        }
        if (! $batchTracking && (! empty($validated['opening_batch_number']) || ! empty($validated['opening_expiry_date']))) {
            throw ValidationException::withMessages([
                'batch_tracking' => ['Batch number or expiry can be entered only when batch tracking is enabled.'],
            ]);
        }
        if ($openingQuantity <= 0 && ! empty($validated['opening_batch_number'])) {
            throw ValidationException::withMessages([
                'opening_batch_number' => ['An opening batch requires opening stock quantity.'],
            ]);
        }
    }

    private function normalizeIdentifiers(Request $request): void
    {
        foreach (['name', 'sku', 'barcode', 'hsn_sac', 'unit_code', 'opening_batch_number'] as $field) {
            if (is_string($request->input($field))) {
                $value = trim($request->input($field));
                if ($field === 'sku') {
                    $value = strtoupper($value);
                }
                $request->merge([$field => $value !== '' ? $value : null]);
            }
        }
    }

    private function assertCategory(int $userId, int $businessId, ?int $categoryId): void
    {
        if (! $categoryId) {
            return;
        }

        ProductCategory::query()
            ->where('user_id', $userId)
            ->where('business_id', $businessId)
            ->findOrFail($categoryId);
    }

    private function ownedProduct(Request $request, int $id): Product
    {
        return Product::query()
            ->where('user_id', $request->user()->id)
            ->where('business_id', $request->user()->activeBusiness->id)
            ->findOrFail($id);
    }

    private function movement(
        int $userId,
        int $businessId,
        Product $product,
        ?InventoryBatch $batch,
        string $type,
        ?string $sourceType,
        ?int $sourceId,
        float $quantity,
        float $unitCost,
        ?string $notes = null
    ): InventoryMovement {
        return InventoryMovement::create([
            'user_id' => $userId,
            'business_id' => $businessId,
            'product_id' => $product->id,
            'batch_id' => $batch?->id,
            'movement_type' => $type,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'quantity_delta' => $quantity,
            'unit_cost' => $unitCost,
            'notes' => $notes,
        ]);
    }

    private function averageUnitCost(Product $product): float
    {
        $quantity = (float) $product->batches()->sum('quantity_remaining');
        if ($quantity <= 0) {
            return (float) $product->purchase_price;
        }

        return (float) $product->batches()
            ->selectRaw('SUM(quantity_remaining * unit_cost) / ? as average_cost', [$quantity])
            ->value('average_cost');
    }

    private function consumeStock(Product $product, float $quantity, string $sourceType, int $sourceId): float
    {
        $remaining = $quantity;
        $costTotal = 0.0;
        $batches = $product->batches()
            ->where('quantity_remaining', '>', 0)
            ->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('expiry_date')
            ->orderBy('received_date')
            ->lockForUpdate()
            ->get();

        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }
            $used = min($remaining, (float) $batch->quantity_remaining);
            $batch->decrement('quantity_remaining', $used);
            $costTotal += $used * (float) $batch->unit_cost;
            $this->movement(
                $product->user_id,
                $product->business_id,
                $product,
                $batch,
                $sourceType,
                $sourceType,
                $sourceId,
                -$used,
                (float) $batch->unit_cost
            );
            $remaining = round($remaining - $used, 3);
        }

        if ($remaining > 0) {
            throw ValidationException::withMessages([
                'counted_quantity' => ['Stock cannot be reduced below the quantity available in inventory batches.'],
            ]);
        }

        return round($costTotal, 2);
    }

    private function postAdjustmentJournal(
        int $userId,
        int $businessId,
        int $productId,
        string $sourceType,
        int $sourceId,
        float $amount,
        string $description,
        bool $stockIncrease
    ): void {
        if ($amount <= 0) {
            return;
        }
        $entry = JournalEntry::create([
            'user_id' => $userId,
            'business_id' => $businessId,
            'entry_date' => now()->toDateString(),
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'description' => $description,
        ]);
        $entry->lines()->createMany($stockIncrease ? [
            ['account_code' => 'inventory', 'debit' => $amount, 'credit' => 0],
            ['account_code' => $sourceType === 'inventory_opening' ? 'opening_balance_equity' : 'inventory_adjustment_gain', 'debit' => 0, 'credit' => $amount],
        ] : [
            ['account_code' => 'inventory_adjustment_loss', 'debit' => $amount, 'credit' => 0],
            ['account_code' => 'inventory', 'debit' => 0, 'credit' => $amount],
        ]);
        $totals = $entry->lines()->selectRaw('SUM(debit) as debits, SUM(credit) as credits')->first();
        if (round((float) $totals->debits, 2) !== round((float) $totals->credits, 2)) {
            throw new LogicException('Inventory adjustment journal must balance.');
        }
    }
}
