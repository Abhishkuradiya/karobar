<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\ReturnNote;
use App\Models\Supplier;
use App\Models\User;
use App\Services\InventoryService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use LogicException;

class PurchaseController extends Controller
{
    private const PAYMENT_METHODS = ['cash', 'upi', 'card', 'bank', 'credit'];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $businessId = $user->activeBusiness->id;
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        if (! empty($validated['from']) && ! empty($validated['to'])
            && $validated['from'] > $validated['to']) {
            throw ValidationException::withMessages([
                'to' => ['The end date must be on or after the start date.'],
            ]);
        }

        $baseQuery = Purchase::query()
            ->where('user_id', $user->id)
            ->where('business_id', $businessId)
            ->when($validated['from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('purchase_date', '>=', $date))
            ->when($validated['to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('purchase_date', '<=', $date))
            ->when($validated['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('purchase_number', 'like', "%{$search}%")
                        ->orWhere('supplier_bill_number', 'like', "%{$search}%")
                        ->orWhere('supplier_name_snapshot', 'like', "%{$search}%")
                        ->orWhereHas('items', fn (Builder $items) => $items->where('description', 'like', "%{$search}%"));
                });
            });

        $purchases = (clone $baseQuery)
            ->with(['supplier', 'items'])
            ->orderByDesc('purchase_date')
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 15)
            ->withQueryString();

        return response()->json([
            'data' => $purchases->items(),
            'meta' => [
                'current_page' => $purchases->currentPage(),
                'last_page' => $purchases->lastPage(),
                'per_page' => $purchases->perPage(),
                'total' => $purchases->total(),
            ],
            'summary' => [
                'count' => (clone $baseQuery)->count(),
                'total' => (clone $baseQuery)->sum('total'),
                'outstanding' => (clone $baseQuery)->sum('balance_due'),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules($request));
        $this->assertValidGstOptions($validated);
        $calculated = $this->calculate($validated);
        $user = $request->user();
        $businessId = $user->activeBusiness->id;

        $purchase = DB::transaction(function () use ($validated, $calculated, $user, $businessId): Purchase {
            $supplier = $this->lockSupplier($user, $businessId, $validated['supplier_id'] ?? null);
            $purchase = $user->purchases()->create([
                'business_id' => $businessId,
                ...$calculated['totals'],
                'supplier_id' => $supplier?->id,
                'bank_account_id' => $validated['bank_account_id'] ?? null,
                'supplier_name_snapshot' => $supplier?->name ?? 'No supplier (paid now)',
                'purchase_date' => $validated['purchase_date'],
                'supplier_bill_number' => $validated['supplier_bill_number'] ?? null,
                'supplier_bill_date' => $validated['supplier_bill_date'] ?? null,
                'payment_method' => $validated['payment_method'],
                'prices_include_gst' => $validated['prices_include_gst'],
                'claim_input_tax_credit' => $validated['claim_input_tax_credit'],
                'reverse_charge' => $validated['reverse_charge'],
                'notes' => $validated['notes'] ?? null,
                'due_date' => $this->dueDate(
                    $validated['purchase_date'],
                    $supplier,
                    $validated['payment_method']
                ),
                'purchase_number' => 'TMP-'.Str::uuid(),
            ]);

            $purchase->update([
                'purchase_number' => sprintf(
                    'PUR-%s-%06d',
                    Carbon::parse($purchase->purchase_date)->format('Ymd'),
                    $purchase->id
                ),
            ]);
            $purchase->items()->createMany($calculated['items']);
            app(InventoryService::class)->receivePurchase($purchase);
            $this->postJournal($purchase);

            return $purchase->fresh(['supplier', 'items']);
        }, 3);

        return response()->json($purchase, 201);
    }

    public function show(Request $request, int $purchase): JsonResponse
    {
        return response()->json(
            $this->ownedPurchase($request, $purchase)->load(['supplier', 'items'])
        );
    }

    public function items(Request $request, int $purchase): JsonResponse
    {
        $record = $this->ownedPurchase($request, $purchase);
        $items = $record->items()->orderBy('id')->paginate(50)->withQueryString();

        return response()->json([
            'purchase_id' => $record->id,
            'data' => $items->items(),
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
            ],
        ]);
    }

    public function storeItem(Request $request, int $purchase): JsonResponse
    {
        $user = $request->user();
        $businessId = $user->activeBusiness->id;
        $record = $this->ownedPurchase($request, $purchase);
        $this->assertNoReturnNotes($businessId, $record->id);
        $validated = $request->validate([
            'product_id' => [
                'nullable',
                'integer',
                Rule::exists('products', 'id')->where(fn (QueryBuilder $query) => $query
                    ->where('user_id', $user->id)
                    ->where('business_id', $businessId)
                    ->whereNull('deleted_at')),
            ],
            'description' => ['required', 'string', 'max:255'],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:999999999.999', 'decimal:0,3'],
            'unit_rate' => ['required', 'numeric', 'min:0', 'max:999999999999.99', 'decimal:0,2'],
            'gst_rate' => ['required', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'discount' => ['required', 'numeric', 'min:0', 'max:999999999999.99', 'decimal:0,2'],
            'hsn_sac' => ['nullable', 'string', 'max:20'],
            'batch_number' => ['nullable', 'string', 'max:80'],
            'expiry_date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        if (! empty($validated['expiry_date']) && $validated['expiry_date'] < $record->purchase_date->format('Y-m-d')) {
            throw ValidationException::withMessages([
                'expiry_date' => ['The expiry date must be on or after the purchase date.'],
            ]);
        }
        $gross = round((float) $validated['quantity'] * (float) $validated['unit_rate'], 2);
        if ((float) $validated['discount'] > $gross) {
            throw ValidationException::withMessages([
                'discount' => ['The discount cannot be greater than the item amount.'],
            ]);
        }

        $updated = DB::transaction(function () use ($request, $user, $businessId, $purchase, $validated): array {
            $record = Purchase::query()
                ->where('user_id', $user->id)
                ->where('business_id', $businessId)
                ->lockForUpdate()
                ->findOrFail($purchase);
            $this->assertNoReturnNotes($businessId, $record->id);
            $itemInputs = $record->items()->orderBy('id')->get()->map(static fn ($item): array => [
                'product_id' => $item->product_id,
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit_rate' => $item->unit_rate,
                'gst_rate' => $item->gst_rate,
                'discount' => $item->discount,
                'hsn_sac' => $item->hsn_sac,
                'batch_number' => $item->batch_number,
                'expiry_date' => $item->expiry_date?->format('Y-m-d'),
            ])->all();
            $itemInputs[] = $validated;
            $calculated = $this->calculate([
                'items' => $itemInputs,
                'prices_include_gst' => $record->prices_include_gst,
                'claim_input_tax_credit' => $record->claim_input_tax_credit,
                'reverse_charge' => $record->reverse_charge,
                'payment_method' => $record->payment_method,
            ]);
            $newItemData = array_pop($calculated['items']);
            $item = $record->items()->create($newItemData);
            app(InventoryService::class)->receivePurchaseItem($record, $item->load('product'));
            $record->update($calculated['totals']);
            $entry = $this->postAddedItemJournal($record->fresh(), $item);
            $record->update(['current_journal_entry_id' => $entry->id]);

            return [
                'purchase' => $record->fresh(['supplier']),
                'item' => $item->fresh(),
            ];
        }, 3);

        return response()->json($updated, 201);
    }

    public function update(Request $request, int $purchase): JsonResponse
    {
        $this->ownedPurchase($request, $purchase);
        $this->assertNoReturnNotes($request->user()->activeBusiness->id, $purchase);
        $validated = $request->validate($this->rules($request, $purchase));
        $this->assertValidGstOptions($validated);
        $calculated = $this->calculate($validated);
        $user = $request->user();
        $businessId = $user->activeBusiness->id;

        $record = DB::transaction(function () use ($purchase, $validated, $calculated, $user, $businessId): Purchase {
            $record = Purchase::query()
                ->where('user_id', $user->id)
                ->where('business_id', $businessId)
                ->lockForUpdate()
                ->findOrFail($purchase);
            $this->assertNoReturnNotes($businessId, $record->id);
            $supplier = $this->lockSupplier($user, $businessId, $validated['supplier_id'] ?? null);

            $this->reverseCurrentJournal($record);
            app(InventoryService::class)->reversePurchase($record);
            $record->items()->delete();
            $record->update([
                ...$calculated['totals'],
                'supplier_id' => $supplier?->id,
                'bank_account_id' => $validated['bank_account_id'] ?? null,
                'supplier_name_snapshot' => $supplier?->name ?? 'No supplier (paid now)',
                'purchase_date' => $validated['purchase_date'],
                'supplier_bill_number' => $validated['supplier_bill_number'] ?? null,
                'supplier_bill_date' => $validated['supplier_bill_date'] ?? null,
                'payment_method' => $validated['payment_method'],
                'prices_include_gst' => $validated['prices_include_gst'],
                'claim_input_tax_credit' => $validated['claim_input_tax_credit'],
                'reverse_charge' => $validated['reverse_charge'],
                'notes' => $validated['notes'] ?? null,
                'due_date' => $this->dueDate(
                    $validated['purchase_date'],
                    $supplier,
                    $validated['payment_method']
                ),
            ]);
            $record->items()->createMany($calculated['items']);
            app(InventoryService::class)->receivePurchase($record);
            $this->postJournal($record);

            return $record->fresh(['supplier', 'items']);
        }, 3);

        return response()->json($record);
    }

    public function destroy(Request $request, int $purchase): JsonResponse
    {
        $user = $request->user();
        $businessId = $user->activeBusiness->id;

        DB::transaction(function () use ($user, $businessId, $purchase): void {
            $record = Purchase::query()
                ->where('user_id', $user->id)
                ->where('business_id', $businessId)
                ->lockForUpdate()
                ->findOrFail($purchase);
            $this->assertNoReturnNotes($businessId, $record->id);
            $this->reverseCurrentJournal($record);
            app(InventoryService::class)->reversePurchase($record);
            $record->delete();
        }, 3);

        return response()->json(null, 204);
    }

    private function rules(Request $request, ?int $ignorePurchaseId = null): array
    {
        $user = $request->user();
        $businessId = $user->activeBusiness->id;
        $billNumberUnique = Rule::unique('purchases', 'supplier_bill_number')
            ->where(fn (QueryBuilder $query) => $query
                ->where('business_id', $businessId)
                ->where('supplier_id', $request->input('supplier_id')));

        if ($ignorePurchaseId) {
            $billNumberUnique->ignore($ignorePurchaseId);
        }

        return [
            'supplier_id' => [
                'nullable',
                'required_if:payment_method,credit',
                'integer',
                Rule::exists('suppliers', 'id')->where(fn (QueryBuilder $query) => $query
                    ->where('user_id', $user->id)
                    ->where('business_id', $businessId)
                    ->whereNull('deleted_at')),
            ],
            'purchase_date' => ['required', 'date_format:Y-m-d'],
            'supplier_bill_number' => ['nullable', 'string', 'max:60', $billNumberUnique],
            'supplier_bill_date' => ['nullable', 'date_format:Y-m-d'],
            'payment_method' => ['required', Rule::in(self::PAYMENT_METHODS)],
            'bank_account_id' => [
                'nullable',
                'integer',
                Rule::exists('bank_accounts', 'id')->where(fn (QueryBuilder $query) => $query
                    ->where('user_id', $user->id)
                    ->where('business_id', $businessId)
                    ->where('is_active', true)
                    ->whereNull('deleted_at')),
            ],
            'prices_include_gst' => ['required', 'boolean'],
            'claim_input_tax_credit' => ['required', 'boolean'],
            'reverse_charge' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999999.999', 'decimal:0,3'],
            'items.*.unit_rate' => ['required', 'numeric', 'min:0', 'max:999999999999.99', 'decimal:0,2'],
            'items.*.gst_rate' => ['required', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'items.*.discount' => ['required', 'numeric', 'min:0', 'max:999999999999.99', 'decimal:0,2'],
            'items.*.hsn_sac' => ['nullable', 'string', 'max:20'],
            'items.*.product_id' => [
                'nullable',
                'integer',
                Rule::exists('products', 'id')->where(fn (QueryBuilder $query) => $query
                    ->where('user_id', $user->id)
                    ->where('business_id', $businessId)
                    ->whereNull('deleted_at')),
            ],
            'items.*.batch_number' => ['nullable', 'string', 'max:80'],
            'items.*.expiry_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:purchase_date'],
        ];
    }

    private function assertValidGstOptions(array $validated): void
    {
        if ($validated['reverse_charge'] && $validated['prices_include_gst']) {
            throw ValidationException::withMessages([
                'prices_include_gst' => ['Prices must exclude GST when reverse charge applies.'],
            ]);
        }
    }

    private function calculate(array $validated): array
    {
        $items = [];
        $subtotal = 0.0;
        $discountTotal = 0.0;
        $taxableAmount = 0.0;
        $taxAmount = 0.0;
        $total = 0.0;

        foreach ($validated['items'] as $index => $item) {
            $quantity = (float) $item['quantity'];
            $unitRate = (float) $item['unit_rate'];
            $gstRate = (float) $item['gst_rate'];
            $discount = round((float) $item['discount'], 2);
            $gross = round($quantity * $unitRate, 2);

            if ($discount > $gross) {
                throw ValidationException::withMessages([
                    "items.{$index}.discount" => ['The discount cannot be greater than the item amount.'],
                ]);
            }

            $afterDiscount = round($gross - $discount, 2);

            if ($validated['prices_include_gst']) {
                $lineTax = $gstRate > 0
                    ? round($afterDiscount * $gstRate / (100 + $gstRate), 2)
                    : 0;
                $lineTaxable = round($afterDiscount - $lineTax, 2);
                $lineTotal = $afterDiscount;
            } else {
                $lineTaxable = $afterDiscount;
                $lineTax = round($lineTaxable * $gstRate / 100, 2);
                $lineTotal = round($lineTaxable + $lineTax, 2);
            }

            $subtotal = round($subtotal + $gross, 2);
            $discountTotal = round($discountTotal + $discount, 2);
            $taxableAmount = round($taxableAmount + $lineTaxable, 2);
            $taxAmount = round($taxAmount + $lineTax, 2);
            $total = round($total + $lineTotal, 2);

            $items[] = [
                'product_id' => $item['product_id'] ?? null,
                'description' => $item['description'],
                'quantity' => $quantity,
                'unit_rate' => $unitRate,
                'gst_rate' => $gstRate,
                'discount' => $discount,
                'hsn_sac' => $item['hsn_sac'] ?? null,
                'batch_number' => $item['batch_number'] ?? null,
                'expiry_date' => $item['expiry_date'] ?? null,
                'taxable_amount' => $lineTaxable,
                'tax_amount' => $lineTax,
                'line_total' => $lineTotal,
            ];
        }

        if ($total <= 0) {
            throw ValidationException::withMessages([
                'items' => ['The purchase total must be greater than zero.'],
            ]);
        }

        $supplierAmount = $validated['reverse_charge'] ? $taxableAmount : $total;
        $balanceDue = $validated['payment_method'] === 'credit' ? $supplierAmount : 0;

        return [
            'items' => $items,
            'totals' => [
                'subtotal' => $subtotal,
                'discount_total' => $discountTotal,
                'taxable_amount' => $taxableAmount,
                'tax_amount' => $taxAmount,
                'total' => $total,
                'supplier_amount' => $supplierAmount,
                'amount_paid' => round($supplierAmount - $balanceDue, 2),
                'balance_due' => $balanceDue,
            ],
        ];
    }

    private function lockSupplier(User $user, int $businessId, ?int $supplierId): ?Supplier
    {
        if (! $supplierId) {
            return null;
        }

        return Supplier::query()
            ->where('user_id', $user->id)
            ->where('business_id', $businessId)
            ->lockForUpdate()
            ->findOrFail($supplierId);
    }

    private function dueDate(string $purchaseDate, ?Supplier $supplier, string $paymentMethod): ?string
    {
        if ($paymentMethod !== 'credit') {
            return null;
        }

        return Carbon::parse($purchaseDate)
            ->addDays($supplier?->credit_days ?? 0)
            ->toDateString();
    }

    private function ownedPurchase(Request $request, int $id): Purchase
    {
        return Purchase::query()
            ->where('user_id', $request->user()->id)
            ->where('business_id', $request->user()->activeBusiness->id)
            ->findOrFail($id);
    }

    private function assertNoReturnNotes(int $businessId, int $purchaseId): void
    {
        if (ReturnNote::query()->where('business_id', $businessId)->where('purchase_id', $purchaseId)->exists()) {
            throw ValidationException::withMessages([
                'purchase' => ['This purchase has debit notes and can no longer be edited or deleted.'],
            ]);
        }
    }

    private function postJournal(Purchase $purchase): void
    {
        $creditAccount = match ($purchase->payment_method) {
            'cash' => 'cash',
            'upi' => 'upi_clearing',
            'card' => 'card_clearing',
            'bank' => 'bank',
            'credit' => 'accounts_payable',
        };

        $entry = JournalEntry::create([
            'user_id' => $purchase->user_id,
            'business_id' => $purchase->business_id,
            'entry_date' => $purchase->purchase_date,
            'source_type' => 'purchase',
            'source_id' => $purchase->id,
            'description' => "Purchase {$purchase->purchase_number} from {$purchase->supplier_name_snapshot}",
        ]);

        $lines = [[
            'account_code' => $creditAccount,
            'bank_account_id' => $purchase->payment_method === 'bank' ? $purchase->bank_account_id : null,
            'debit' => 0,
            'credit' => $purchase->supplier_amount,
        ]];

        $inventoryAmount = 0.0;
        $expenseAmount = 0.0;
        foreach ($purchase->items()->with('product')->get() as $item) {
            $isStockItem = $item->product && $item->product->track_stock && ! $item->product->is_service;
            $itemAmount = $purchase->claim_input_tax_credit
                ? (float) $item->taxable_amount
                : (float) $item->line_total;
            if ($isStockItem) {
                $inventoryAmount += $itemAmount;
            } else {
                $expenseAmount += $itemAmount;
            }
        }
        if ($expenseAmount > 0) {
            $lines[] = [
                'account_code' => 'purchase_expense',
                'debit' => round($expenseAmount, 2),
                'credit' => 0,
            ];
        }
        if ($inventoryAmount > 0) {
            $lines[] = [
                'account_code' => 'inventory',
                'debit' => round($inventoryAmount, 2),
                'credit' => 0,
            ];
        }

        if ($purchase->claim_input_tax_credit && (float) $purchase->tax_amount > 0) {
            $lines[] = [
                'account_code' => 'input_gst',
                'debit' => $purchase->tax_amount,
                'credit' => 0,
            ];
        }

        if ($purchase->reverse_charge && (float) $purchase->tax_amount > 0) {
            $lines[] = [
                'account_code' => 'reverse_charge_gst_payable',
                'debit' => 0,
                'credit' => $purchase->tax_amount,
            ];
        }

        $entry->lines()->createMany($lines);
        $this->assertJournalBalances($entry);
        $purchase->update(['current_journal_entry_id' => $entry->id]);
    }

    private function reverseCurrentJournal(Purchase $purchase): void
    {
        $alreadyReversed = JournalEntry::query()
            ->whereNotNull('reversal_of_id')
            ->select('reversal_of_id');
        $entries = JournalEntry::query()
            ->where('user_id', $purchase->user_id)
            ->where('business_id', $purchase->business_id)
            ->where('source_id', $purchase->id)
            ->whereIn('source_type', ['purchase', 'purchase_item_addition'])
            ->whereNotIn('id', $alreadyReversed)
            ->with('lines')
            ->lockForUpdate()
            ->get();

        foreach ($entries as $original) {
            $reversal = JournalEntry::create([
                'user_id' => $purchase->user_id,
                'business_id' => $purchase->business_id,
                'entry_date' => now()->toDateString(),
                'source_type' => 'purchase_reversal',
                'source_id' => $purchase->id,
                'description' => "Reversal of purchase entry {$original->id} for {$purchase->purchase_number}",
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
            $this->assertJournalBalances($reversal);
        }

        $purchase->update(['current_journal_entry_id' => null]);
    }

    private function postAddedItemJournal(Purchase $purchase, \App\Models\PurchaseItem $item): JournalEntry
    {
        $creditAccount = match ($purchase->payment_method) {
            'cash' => 'cash',
            'upi' => 'upi_clearing',
            'card' => 'card_clearing',
            'bank' => 'bank',
            'credit' => 'accounts_payable',
        };
        $product = $item->product;
        $isStockItem = $product && $product->track_stock && ! $product->is_service;
        $itemAmount = $purchase->claim_input_tax_credit
            ? (float) $item->taxable_amount
            : (float) $item->line_total;
        $supplierAmount = $purchase->reverse_charge
            ? (float) $item->taxable_amount
            : (float) $item->line_total;
        $entry = JournalEntry::create([
            'user_id' => $purchase->user_id,
            'business_id' => $purchase->business_id,
            'entry_date' => $purchase->purchase_date,
            'source_type' => 'purchase_item_addition',
            'source_id' => $purchase->id,
            'description' => "Additional item for purchase {$purchase->purchase_number}: {$item->description}",
        ]);
        $lines = [[
            'account_code' => $creditAccount,
            'bank_account_id' => $purchase->payment_method === 'bank' ? $purchase->bank_account_id : null,
            'debit' => 0,
            'credit' => $supplierAmount,
        ], [
            'account_code' => $isStockItem ? 'inventory' : 'purchase_expense',
            'debit' => $itemAmount,
            'credit' => 0,
        ]];

        if ($purchase->claim_input_tax_credit && (float) $item->tax_amount > 0) {
            $lines[] = ['account_code' => 'input_gst', 'debit' => $item->tax_amount, 'credit' => 0];
        }
        if ($purchase->reverse_charge && (float) $item->tax_amount > 0) {
            $lines[] = ['account_code' => 'reverse_charge_gst_payable', 'debit' => 0, 'credit' => $item->tax_amount];
        }

        $entry->lines()->createMany($lines);
        $this->assertJournalBalances($entry);

        return $entry;
    }

    private function assertJournalBalances(JournalEntry $entry): void
    {
        $totals = $entry->lines()
            ->selectRaw('SUM(debit) as debits, SUM(credit) as credits')
            ->first();

        if ($this->cents((float) $totals->debits) !== $this->cents((float) $totals->credits)) {
            throw new LogicException('A purchase journal entry must balance before it can be committed.');
        }
    }

    private function cents(float $amount): int
    {
        return (int) round($amount * 100);
    }
}
