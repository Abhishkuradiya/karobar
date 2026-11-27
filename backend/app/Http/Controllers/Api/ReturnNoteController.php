<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryMovement;
use App\Models\JournalEntry;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\ReturnNote;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Services\InventoryService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use LogicException;

class ReturnNoteController extends Controller
{
    private const SETTLEMENTS = ['cash', 'upi', 'card', 'bank', 'credit'];

    public function creditNotes(Request $request): JsonResponse
    {
        return $this->index($request, 'credit');
    }

    public function debitNotes(Request $request): JsonResponse
    {
        return $this->index($request, 'debit');
    }

    public function storeCredit(Request $request): JsonResponse
    {
        return $this->store($request, 'credit');
    }

    public function storeDebit(Request $request): JsonResponse
    {
        return $this->store($request, 'debit');
    }

    public function updateCredit(Request $request, int $note): JsonResponse
    {
        return $this->store($request, 'credit', $note);
    }

    public function updateDebit(Request $request, int $note): JsonResponse
    {
        return $this->store($request, 'debit', $note);
    }

    public function destroyCredit(Request $request, int $note): JsonResponse
    {
        return $this->destroy($request, 'credit', $note);
    }

    public function destroyDebit(Request $request, int $note): JsonResponse
    {
        return $this->destroy($request, 'debit', $note);
    }

    private function index(Request $request, string $type): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        if (! empty($validated['from']) && ! empty($validated['to']) && $validated['from'] > $validated['to']) {
            throw ValidationException::withMessages(['to' => ['The end date must be on or after the start date.']]);
        }

        $query = ReturnNote::query()
            ->where('user_id', $request->user()->id)
            ->where('business_id', $request->user()->activeBusiness->id)
            ->where('note_type', $type)
            ->when($validated['from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('note_date', '>=', $date))
            ->when($validated['to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('note_date', '<=', $date))
            ->with(['items', 'sale:id,invoice_number', 'purchase:id,purchase_number', 'currentJournalEntry.lines']);
        $notes = $query->orderByDesc('note_date')->orderByDesc('id')->get();

        return response()->json([
            'data' => $notes,
            'summary' => [
                'count' => $notes->count(),
                'total' => round((float) $notes->sum('total'), 2),
            ],
        ]);
    }

    private function store(Request $request, string $type, ?int $existingNoteId = null): JsonResponse
    {
        $user = $request->user();
        $businessId = $user->activeBusiness->id;
        $validated = $request->validate([
            'sale_id' => [$type === 'credit' ? 'required' : 'prohibited', 'integer'],
            'purchase_id' => [$type === 'debit' ? 'required' : 'prohibited', 'integer'],
            'note_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'settlement_method' => ['required', Rule::in(self::SETTLEMENTS)],
            'bank_account_id' => [
                'required_if:settlement_method,bank',
                'nullable',
                'integer',
                Rule::exists('bank_accounts', 'id')->where(fn (QueryBuilder $query) => $query
                    ->where('user_id', $user->id)
                    ->where('business_id', $businessId)
                    ->where('is_active', true)
                    ->whereNull('deleted_at')),
            ],
            'reason' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.item_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999999.999', 'decimal:0,3'],
        ]);

        $note = DB::transaction(function () use ($validated, $type, $user, $businessId, $existingNoteId): ReturnNote {
            $existingNote = null;
            $existingItems = collect();
            $preserveInventory = false;
            if ($existingNoteId) {
                $existingNote = ReturnNote::query()
                    ->where('user_id', $user->id)
                    ->where('business_id', $businessId)
                    ->where('note_type', $type)
                    ->with('items')
                    ->lockForUpdate()
                    ->findOrFail($existingNoteId);
                $sourceId = $type === 'credit' ? $existingNote->sale_id : $existingNote->purchase_id;
                $requestedSourceId = $type === 'credit' ? $validated['sale_id'] : $validated['purchase_id'];
                if ((int) $requestedSourceId !== (int) $sourceId) {
                    throw ValidationException::withMessages([
                        $type === 'credit' ? 'sale_id' : 'purchase_id' => ['A return note must remain linked to its original transaction.'],
                    ]);
                }
                $source = $type === 'credit'
                    ? Sale::query()->where('user_id', $user->id)->where('business_id', $businessId)->lockForUpdate()->findOrFail($sourceId)
                    : Purchase::query()->where('user_id', $user->id)->where('business_id', $businessId)->lockForUpdate()->findOrFail($sourceId);
                $itemColumn = $type === 'credit' ? 'original_sale_item_id' : 'original_purchase_item_id';
                $existingItems = $existingNote->items->keyBy($itemColumn);
                $preserveInventory = $this->sameReturnedQuantities($existingNote, $type, $validated['items']);
                $this->reverseCurrentJournal($existingNote);
                if (! $preserveInventory) {
                    app(InventoryService::class)->reverseReturnNote($existingNote);
                }
                if ($existingNote->settlement_method === 'credit') {
                    $source->update(['balance_due' => round((float) $source->balance_due + (float) $existingNote->total, 2)]);
                }
            } else {
                $source = $type === 'credit'
                    ? Sale::query()->where('user_id', $user->id)->where('business_id', $businessId)->lockForUpdate()->findOrFail($validated['sale_id'])
                    : Purchase::query()->where('user_id', $user->id)->where('business_id', $businessId)->lockForUpdate()->findOrFail($validated['purchase_id']);
            }

            if ($type === 'debit' && $source->reverse_charge) {
                throw ValidationException::withMessages([
                    'purchase_id' => ['Debit notes for reverse-charge purchases are not supported. Record the adjustment with your accountant.'],
                ]);
            }
            if (($source->payment_method === 'credit') !== ($validated['settlement_method'] === 'credit')) {
                throw ValidationException::withMessages([
                    'settlement_method' => [$source->payment_method === 'credit'
                        ? 'Credit transactions must be adjusted against the outstanding balance.'
                        : 'Choose a cash, UPI, card or bank settlement for a transaction that was already paid.'],
                ]);
            }
            if ($validated['settlement_method'] === 'bank' && empty($validated['bank_account_id'])) {
                throw ValidationException::withMessages([
                    'bank_account_id' => ['Select the bank account used for the refund or supplier settlement.'],
                ]);
            }

            $items = [];
            $taxableTotal = 0.0;
            $taxTotal = 0.0;
            $noteTotal = 0.0;
            foreach ($validated['items'] as $index => $line) {
                $original = $type === 'credit'
                    ? SaleItem::query()->where('sale_id', $source->id)->lockForUpdate()->findOrFail($line['item_id'])
                    : PurchaseItem::query()->where('purchase_id', $source->id)->lockForUpdate()->findOrFail($line['item_id']);
                $returned = $this->previouslyReturned($type, $original->id, $existingNoteId);
                $remaining = round((float) $original->quantity - $returned['quantity'], 3);
                $quantity = (float) $line['quantity'];
                if ($quantity > $remaining) {
                    throw ValidationException::withMessages([
                        "items.{$index}.quantity" => ["Only {$remaining} unit(s) remain available for return."],
                    ]);
                }

                $isLastReturn = round($quantity - $remaining, 3) === 0.0;
                $ratio = $quantity / (float) $original->quantity;
                $taxable = $isLastReturn
                    ? round((float) $original->taxable_amount - $returned['taxable_amount'], 2)
                    : round((float) $original->taxable_amount * $ratio, 2);
                $tax = $isLastReturn
                    ? round((float) $original->tax_amount - $returned['tax_amount'], 2)
                    : round((float) $original->tax_amount * $ratio, 2);
                $lineTotal = $isLastReturn
                    ? round((float) $original->line_total - $returned['line_total'], 2)
                    : round((float) $original->line_total * $ratio, 2);
                $taxableTotal += $taxable;
                $taxTotal += $tax;
                $noteTotal += $lineTotal;
                $items[] = [
                    'original_sale_item_id' => $type === 'credit' ? $original->id : null,
                    'original_purchase_item_id' => $type === 'debit' ? $original->id : null,
                    'product_id' => $original->product_id,
                    'description' => $original->description,
                    'quantity' => $quantity,
                    'unit_rate' => $original->unit_rate,
                    'gst_rate' => $original->gst_rate,
                    'taxable_amount' => $taxable,
                    'tax_amount' => $tax,
                    'line_total' => $lineTotal,
                    'inventory_cost' => 0,
                    '_original' => $original,
                ];
            }
            $taxableTotal = round($taxableTotal, 2);
            $taxTotal = round($taxTotal, 2);
            $noteTotal = round($noteTotal, 2);
            if ($noteTotal <= 0) {
                throw ValidationException::withMessages(['items' => ['The return total must be greater than zero.']]);
            }
            if ($validated['settlement_method'] === 'credit' && (float) $source->balance_due < $noteTotal) {
                throw ValidationException::withMessages([
                    'settlement_method' => ['The return exceeds the outstanding balance. Choose another settlement method for the excess.'],
                ]);
            }

            $noteAttributes = [
                'counterparty_name_snapshot' => $type === 'credit'
                    ? $source->customer_name_snapshot
                    : $source->supplier_name_snapshot,
                'note_date' => $validated['note_date'],
                'settlement_method' => $validated['settlement_method'],
                'reason' => $validated['reason'] ?? null,
                'taxable_amount' => $taxableTotal,
                'tax_amount' => $taxTotal,
                'total' => $noteTotal,
            ];
            if ($existingNote) {
                $note = $existingNote;
                $note->items()->delete();
                $note->update($noteAttributes);
            } else {
                $note = ReturnNote::create([
                    'user_id' => $user->id,
                    'business_id' => $businessId,
                    'sale_id' => $type === 'credit' ? $source->id : null,
                    'purchase_id' => $type === 'debit' ? $source->id : null,
                    'note_type' => $type,
                    'note_number' => 'TMP-'.Str::uuid(),
                    ...$noteAttributes,
                ]);
                $note->update([
                    'note_number' => sprintf(
                    '%s-%s-%06d',
                    $type === 'credit' ? 'CN' : 'DN',
                    str_replace('-', '', $validated['note_date']),
                    $note->id
                    ),
                ]);
            }

            foreach ($items as $item) {
                $original = $item['_original'];
                unset($item['_original']);
                if ($preserveInventory) {
                    $item['inventory_cost'] = (float) ($existingItems->get($original->id)?->inventory_cost ?? 0);
                } elseif ($type === 'credit' && $original->product && $original->product->track_stock
                    && InventoryMovement::query()->where('source_type', 'sale')->where('source_id', $source->id)
                        ->where('sale_item_id', $original->id)->exists()) {
                    $item['inventory_cost'] = app(InventoryService::class)
                        ->returnSale($source, $original, $item['quantity'], $note->id);
                } elseif ($type === 'debit' && $original->product && $original->product->track_stock
                    && InventoryMovement::query()->where('source_type', 'purchase')->where('source_id', $source->id)
                        ->where('purchase_item_id', $original->id)->exists()) {
                    $item['inventory_cost'] = app(InventoryService::class)
                        ->returnPurchase($source, $original, $item['quantity'], $note->id);
                }
                $note->items()->create($item);
            }

            if ($validated['settlement_method'] === 'credit') {
                $source->update(['balance_due' => round((float) $source->balance_due - $noteTotal, 2)]);
            }
            $this->postJournal($note, $source, $validated['bank_account_id'] ?? null);

            return $note->fresh(['items', 'sale', 'purchase', 'currentJournalEntry.lines']);
        }, 3);

        return response()->json($note, $existingNoteId ? 200 : 201);
    }

    private function destroy(Request $request, string $type, int $noteId): JsonResponse
    {
        $user = $request->user();
        $businessId = $user->activeBusiness->id;

        DB::transaction(function () use ($user, $businessId, $type, $noteId): void {
            $note = ReturnNote::query()
                ->where('user_id', $user->id)
                ->where('business_id', $businessId)
                ->where('note_type', $type)
                ->with('items')
                ->lockForUpdate()
                ->findOrFail($noteId);
            $source = $type === 'credit'
                ? Sale::query()->where('user_id', $user->id)->where('business_id', $businessId)->lockForUpdate()->findOrFail($note->sale_id)
                : Purchase::query()->where('user_id', $user->id)->where('business_id', $businessId)->lockForUpdate()->findOrFail($note->purchase_id);

            $this->reverseCurrentJournal($note);
            app(InventoryService::class)->reverseReturnNote($note);
            if ($note->settlement_method === 'credit') {
                $source->update(['balance_due' => round((float) $source->balance_due + (float) $note->total, 2)]);
            }
            $note->delete();
        }, 3);

        return response()->json(['message' => 'The return note was deleted and its effects were reversed.']);
    }

    private function sameReturnedQuantities(ReturnNote $note, string $type, array $newItems): bool
    {
        $column = $type === 'credit' ? 'original_sale_item_id' : 'original_purchase_item_id';
        $existing = [];
        foreach ($note->items as $item) {
            $existing[$item->{$column}] = number_format((float) $item->quantity, 3, '.', '');
        }
        $updated = [];
        foreach ($newItems as $item) {
            $updated[$item['item_id']] = number_format((float) $item['quantity'], 3, '.', '');
        }
        ksort($existing);
        ksort($updated);

        return $existing === $updated;
    }

    private function reverseCurrentJournal(ReturnNote $note): void
    {
        if (! $note->current_journal_entry_id) {
            return;
        }

        $original = JournalEntry::query()
            ->where('user_id', $note->user_id)
            ->where('business_id', $note->business_id)
            ->with('lines')
            ->lockForUpdate()
            ->findOrFail($note->current_journal_entry_id);
        $reversal = JournalEntry::create([
            'user_id' => $note->user_id,
            'business_id' => $note->business_id,
            'entry_date' => now()->toDateString(),
            'source_type' => $note->note_type.'_note_reversal',
            'source_id' => $note->id,
            'description' => "Reversal of return note {$note->note_number}",
            'reversal_of_id' => $original->id,
        ]);
        $reversal->lines()->createMany(
            $original->lines->map(fn ($line): array => [
                'account_code' => $line->account_code,
                'bank_account_id' => $line->bank_account_id,
                'debit' => $line->credit,
                'credit' => $line->debit,
            ])->all()
        );
        $totals = $reversal->lines()->selectRaw('SUM(debit) as debits, SUM(credit) as credits')->first();
        if ((int) round((float) $totals->debits * 100) !== (int) round((float) $totals->credits * 100)) {
            throw new LogicException('Return note reversal journal entry must balance.');
        }
        $note->update(['current_journal_entry_id' => null]);
    }

    private function previouslyReturned(string $type, int $originalItemId, ?int $ignoreNoteId = null): array
    {
        $column = $type === 'credit' ? 'original_sale_item_id' : 'original_purchase_item_id';

        $totals = DB::table('return_note_items')
            ->join('return_notes', 'return_notes.id', '=', 'return_note_items.return_note_id')
            ->where("return_note_items.{$column}", $originalItemId)
            ->where('return_notes.note_type', $type)
            ->whereNull('return_notes.deleted_at')
            ->when($ignoreNoteId, fn (QueryBuilder $query) => $query->where('return_notes.id', '!=', $ignoreNoteId))
            ->selectRaw(
                'COALESCE(SUM(return_note_items.quantity), 0) as quantity, '.
                'COALESCE(SUM(return_note_items.taxable_amount), 0) as taxable_amount, '.
                'COALESCE(SUM(return_note_items.tax_amount), 0) as tax_amount, '.
                'COALESCE(SUM(return_note_items.line_total), 0) as line_total'
            )
            ->first();

        return [
            'quantity' => (float) $totals->quantity,
            'taxable_amount' => (float) $totals->taxable_amount,
            'tax_amount' => (float) $totals->tax_amount,
            'line_total' => (float) $totals->line_total,
        ];
    }

    private function postJournal(ReturnNote $note, Sale|Purchase $source, ?int $bankAccountId): void
    {
        $entry = JournalEntry::create([
            'user_id' => $note->user_id,
            'business_id' => $note->business_id,
            'entry_date' => $note->note_date,
            'source_type' => $note->note_type.'_note',
            'source_id' => $note->id,
            'description' => "{$note->note_number} for {$note->counterparty_name_snapshot}",
        ]);
        $settlementAccount = match ($note->settlement_method) {
            'cash' => 'cash',
            'upi' => 'upi_clearing',
            'card' => 'card_clearing',
            'bank' => 'bank',
            'credit' => $note->note_type === 'credit' ? 'accounts_receivable' : 'accounts_payable',
        };
        $settlementLine = [
            'account_code' => $settlementAccount,
            'bank_account_id' => $settlementAccount === 'bank' ? $bankAccountId : null,
            'debit' => $note->note_type === 'debit' ? $note->total : 0,
            'credit' => $note->note_type === 'credit' ? $note->total : 0,
        ];
        $lines = [$settlementLine];

        if ($note->note_type === 'credit') {
            $lines[] = ['account_code' => 'sales_returns', 'debit' => $note->taxable_amount, 'credit' => 0];
            if ((float) $note->tax_amount > 0) {
                $lines[] = ['account_code' => 'gst_payable', 'debit' => $note->tax_amount, 'credit' => 0];
            }
            $inventoryCost = (float) $note->items()->sum('inventory_cost');
            if ($inventoryCost > 0) {
                $lines[] = ['account_code' => 'inventory', 'debit' => $inventoryCost, 'credit' => 0];
                $lines[] = ['account_code' => 'cost_of_goods_sold', 'debit' => 0, 'credit' => $inventoryCost];
            }
        } else {
            $purchase = $source;
            $inventoryCost = (float) $note->items()->sum('inventory_cost');
            $nonStockAmount = 0.0;
            foreach ($note->items()->get() as $item) {
                $isStock = $item->product && $item->product->track_stock && ! $item->product->is_service;
                if (! $isStock) {
                    $nonStockAmount += $purchase->claim_input_tax_credit
                        ? (float) $item->taxable_amount
                        : (float) $item->line_total;
                }
            }
            if ($inventoryCost > 0) {
                $lines[] = ['account_code' => 'inventory', 'debit' => 0, 'credit' => $inventoryCost];
            }
            if ($nonStockAmount > 0) {
                $lines[] = [
                    'account_code' => 'purchase_returns',
                    'debit' => 0,
                    'credit' => round($nonStockAmount, 2),
                ];
            }
            if ($purchase->claim_input_tax_credit && (float) $note->tax_amount > 0) {
                $lines[] = ['account_code' => 'input_gst', 'debit' => 0, 'credit' => $note->tax_amount];
            }
            $creditTotal = array_sum(array_map(fn (array $line): float => (float) $line['credit'], $lines));
            $debitTotal = array_sum(array_map(fn (array $line): float => (float) $line['debit'], $lines));
            if (round($debitTotal, 2) !== round($creditTotal, 2)) {
                $difference = round($debitTotal - $creditTotal, 2);
                if ($difference > 0) {
                    $lines[] = ['account_code' => 'purchase_returns', 'debit' => 0, 'credit' => $difference];
                } else {
                    $lines[] = ['account_code' => 'purchase_return_adjustment', 'debit' => abs($difference), 'credit' => 0];
                }
            }
        }

        $entry->lines()->createMany($lines);
        $totals = $entry->lines()->selectRaw('SUM(debit) as debits, SUM(credit) as credits')->first();
        if ((int) round((float) $totals->debits * 100) !== (int) round((float) $totals->credits * 100)) {
            throw new LogicException('Return note journal entry must balance.');
        }
        $note->update(['current_journal_entry_id' => $entry->id]);
    }
}
