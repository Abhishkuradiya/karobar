<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\Sale;
use App\Models\User;
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

class SaleController extends Controller
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

        $baseQuery = Sale::query()
            ->where('user_id', $user->id)
            ->where('business_id', $businessId)
            ->when($validated['from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('sale_date', '>=', $date))
            ->when($validated['to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('sale_date', '<=', $date))
            ->when($validated['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('invoice_number', 'like', "%{$search}%")
                        ->orWhere('customer_name_snapshot', 'like', "%{$search}%")
                        ->orWhereHas('items', fn (Builder $items) => $items->where('description', 'like', "%{$search}%"));
                });
            });

        $sales = (clone $baseQuery)
            ->with(['customer', 'items'])
            ->orderByDesc('sale_date')
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 15)
            ->withQueryString();

        return response()->json([
            'data' => $sales->items(),
            'meta' => [
                'current_page' => $sales->currentPage(),
                'last_page' => $sales->lastPage(),
                'per_page' => $sales->perPage(),
                'total' => $sales->total(),
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
        $calculated = $this->calculate($validated);
        $user = $request->user();
        $businessId = $user->activeBusiness->id;

        $sale = DB::transaction(function () use ($validated, $calculated, $user, $businessId): Sale {
            $customer = $this->lockCustomer($user, $businessId, $validated['customer_id'] ?? null);
            $this->assertCreditLimit($user, $businessId, $customer, $validated['payment_method'], $calculated['balance_due']);

            $sale = $user->sales()->create([
                'business_id' => $businessId,
                ...$calculated['totals'],
                'customer_id' => $customer?->id,
                'customer_name_snapshot' => $customer?->name ?? 'Walk-in customer (paid now)',
                'sale_date' => $validated['sale_date'],
                'payment_method' => $validated['payment_method'],
                'prices_include_gst' => $validated['prices_include_gst'],
                'export_under_lut' => $validated['export_under_lut'],
                'notes' => $validated['notes'] ?? null,
                'due_date' => $this->dueDate($validated['sale_date'], $customer, $validated['payment_method']),
                'invoice_number' => 'TMP-'.Str::uuid(),
            ]);

            $sale->update([
                'invoice_number' => sprintf(
                    'SAL-%s-%06d',
                    Carbon::parse($sale->sale_date)->format('Ymd'),
                    $sale->id
                ),
            ]);
            $sale->items()->createMany($calculated['items']);
            $this->postJournal($sale);

            return $sale->fresh(['customer', 'items']);
        }, 3);

        return response()->json($sale, 201);
    }

    public function show(Request $request, int $sale): JsonResponse
    {
        return response()->json(
            $this->ownedSale($request, $sale)->load(['customer', 'items'])
        );
    }

    public function update(Request $request, int $sale): JsonResponse
    {
        $this->ownedSale($request, $sale);
        $validated = $request->validate($this->rules($request));
        $calculated = $this->calculate($validated);
        $user = $request->user();

        $businessId = $user->activeBusiness->id;

        $record = DB::transaction(function () use ($sale, $validated, $calculated, $user, $businessId): Sale {
            $record = Sale::query()
                ->where('user_id', $user->id)
                ->where('business_id', $businessId)
                ->lockForUpdate()
                ->findOrFail($sale);
            $customer = $this->lockCustomer($user, $businessId, $validated['customer_id'] ?? null);
            $this->assertCreditLimit(
                $user,
                $businessId,
                $customer,
                $validated['payment_method'],
                $calculated['balance_due'],
                $record->id
            );

            $this->reverseCurrentJournal($record);
            $record->items()->delete();
            $record->update([
                ...$calculated['totals'],
                'customer_id' => $customer?->id,
                'customer_name_snapshot' => $customer?->name ?? 'Walk-in customer (paid now)',
                'sale_date' => $validated['sale_date'],
                'payment_method' => $validated['payment_method'],
                'prices_include_gst' => $validated['prices_include_gst'],
                'export_under_lut' => $validated['export_under_lut'],
                'notes' => $validated['notes'] ?? null,
                'due_date' => $this->dueDate($validated['sale_date'], $customer, $validated['payment_method']),
            ]);
            $record->items()->createMany($calculated['items']);
            $this->postJournal($record);

            return $record->fresh(['customer', 'items']);
        }, 3);

        return response()->json($record);
    }

    public function destroy(Request $request, int $sale): JsonResponse
    {
        $businessId = $request->user()->activeBusiness->id;

        DB::transaction(function () use ($request, $sale, $businessId): void {
            $record = Sale::query()
                ->where('user_id', $request->user()->id)
                ->where('business_id', $businessId)
                ->lockForUpdate()
                ->findOrFail($sale);
            $this->reverseCurrentJournal($record);
            $record->delete();
        }, 3);

        return response()->json(null, 204);
    }

    private function rules(Request $request): array
    {
        return [
            'customer_id' => [
                'nullable',
                'integer',
                Rule::exists('customers', 'id')->where(fn (QueryBuilder $query) => $query
                    ->where('user_id', $request->user()->id)
                    ->where('business_id', $request->user()->activeBusiness->id)
                    ->whereNull('deleted_at')),
            ],
            'sale_date' => ['required', 'date_format:Y-m-d'],
            'payment_method' => ['required', Rule::in(self::PAYMENT_METHODS)],
            'prices_include_gst' => ['required', 'boolean'],
            'export_under_lut' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999999.999', 'decimal:0,3'],
            'items.*.unit_rate' => ['required', 'numeric', 'min:0', 'max:999999999999.99', 'decimal:0,2'],
            'items.*.gst_rate' => ['required', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'items.*.discount' => ['required', 'numeric', 'min:0', 'max:999999999999.99', 'decimal:0,2'],
            'items.*.hsn_sac' => ['nullable', 'string', 'max:20'],
        ];
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
                $lineTotal = $afterDiscount;
                $lineTax = $validated['export_under_lut']
                    ? 0
                    : round($lineTotal * $gstRate / (100 + $gstRate), 2);
                $lineTaxable = round($lineTotal - $lineTax, 2);
            } else {
                $lineTaxable = $afterDiscount;
                $lineTax = $validated['export_under_lut']
                    ? 0
                    : round($lineTaxable * $gstRate / 100, 2);
                $lineTotal = round($lineTaxable + $lineTax, 2);
            }

            $subtotal = round($subtotal + $gross, 2);
            $discountTotal = round($discountTotal + $discount, 2);
            $taxableAmount = round($taxableAmount + $lineTaxable, 2);
            $taxAmount = round($taxAmount + $lineTax, 2);
            $total = round($total + $lineTotal, 2);

            $items[] = [
                'description' => $item['description'],
                'quantity' => $quantity,
                'unit_rate' => $unitRate,
                'gst_rate' => $gstRate,
                'discount' => $discount,
                'hsn_sac' => $item['hsn_sac'] ?? null,
                'taxable_amount' => $lineTaxable,
                'tax_amount' => $lineTax,
                'line_total' => $lineTotal,
            ];
        }

        if ($total <= 0) {
            throw ValidationException::withMessages([
                'items' => ['The sale total must be greater than zero.'],
            ]);
        }

        $balanceDue = $validated['payment_method'] === 'credit' ? $total : 0;

        return [
            'items' => $items,
            'balance_due' => $balanceDue,
            'totals' => [
                'subtotal' => $subtotal,
                'discount_total' => $discountTotal,
                'taxable_amount' => $taxableAmount,
                'tax_amount' => $taxAmount,
                'total' => $total,
                'amount_paid' => round($total - $balanceDue, 2),
                'balance_due' => $balanceDue,
            ],
        ];
    }

    private function lockCustomer(User $user, int $businessId, ?int $customerId): ?Customer
    {
        if (! $customerId) {
            return null;
        }

        return Customer::query()
            ->where('user_id', $user->id)
            ->where('business_id', $businessId)
            ->lockForUpdate()
            ->findOrFail($customerId);
    }

    private function assertCreditLimit(
        User $user,
        int $businessId,
        ?Customer $customer,
        string $paymentMethod,
        float $newBalance,
        ?int $ignoreSaleId = null
    ): void {
        if ($paymentMethod !== 'credit') {
            return;
        }

        if (! $customer) {
            throw ValidationException::withMessages([
                'customer_id' => ['Select a customer before recording a credit sale.'],
            ]);
        }

        if ($customer->credit_limit === null) {
            return;
        }

        $existingBalance = Sale::query()
            ->where('user_id', $user->id)
            ->where('business_id', $businessId)
            ->where('customer_id', $customer->id)
            ->where('payment_method', 'credit')
            ->when($ignoreSaleId, fn (Builder $query, int $id) => $query->where('id', '<>', $id))
            ->sum('balance_due');

        if (round((float) $existingBalance + $newBalance, 2) > (float) $customer->credit_limit) {
            throw ValidationException::withMessages([
                'payment_method' => ['This sale exceeds the customer credit limit.'],
            ]);
        }
    }

    private function dueDate(string $saleDate, ?Customer $customer, string $paymentMethod): ?string
    {
        if ($paymentMethod !== 'credit') {
            return null;
        }

        return Carbon::parse($saleDate)
            ->addDays($customer?->credit_days ?? 0)
            ->toDateString();
    }

    private function ownedSale(Request $request, int $id): Sale
    {
        return Sale::query()
            ->where('user_id', $request->user()->id)
            ->where('business_id', $request->user()->activeBusiness->id)
            ->findOrFail($id);
    }

    private function postJournal(Sale $sale): void
    {
        $debitAccount = match ($sale->payment_method) {
            'cash' => 'cash',
            'upi' => 'upi_clearing',
            'card' => 'card_clearing',
            'bank' => 'bank',
            'credit' => 'accounts_receivable',
        };

        $entry = JournalEntry::create([
            'user_id' => $sale->user_id,
            'business_id' => $sale->business_id,
            'entry_date' => $sale->sale_date,
            'source_type' => 'sale',
            'source_id' => $sale->id,
            'description' => "Sale {$sale->invoice_number} to {$sale->customer_name_snapshot}",
        ]);

        $lines = [[
            'account_code' => $debitAccount,
            'debit' => $sale->total,
            'credit' => 0,
        ], [
            'account_code' => 'sales_revenue',
            'debit' => 0,
            'credit' => $sale->taxable_amount,
        ]];

        if ((float) $sale->tax_amount > 0) {
            $lines[] = [
                'account_code' => 'gst_payable',
                'debit' => 0,
                'credit' => $sale->tax_amount,
            ];
        }

        $entry->lines()->createMany($lines);
        $this->assertJournalBalances($entry);
        $sale->update(['current_journal_entry_id' => $entry->id]);
    }

    private function reverseCurrentJournal(Sale $sale): void
    {
        if (! $sale->current_journal_entry_id) {
            return;
        }

        $original = JournalEntry::query()
            ->where('user_id', $sale->user_id)
            ->where('business_id', $sale->business_id)
            ->with('lines')
            ->findOrFail($sale->current_journal_entry_id);

        $reversal = JournalEntry::create([
            'user_id' => $sale->user_id,
            'business_id' => $sale->business_id,
            'entry_date' => now()->toDateString(),
            'source_type' => 'sale_reversal',
            'source_id' => $sale->id,
            'description' => "Reversal of sale {$sale->invoice_number}",
            'reversal_of_id' => $original->id,
        ]);

        $reversal->lines()->createMany(
            $original->lines->map(fn ($line): array => [
                'account_code' => $line->account_code,
                'debit' => $line->credit,
                'credit' => $line->debit,
            ])->all()
        );
        $this->assertJournalBalances($reversal);
        $sale->update(['current_journal_entry_id' => null]);
    }

    private function assertJournalBalances(JournalEntry $entry): void
    {
        $totals = $entry->lines()
            ->selectRaw('SUM(debit) as debits, SUM(credit) as credits')
            ->first();

        if ($this->cents((float) $totals->debits) !== $this->cents((float) $totals->credits)) {
            throw new LogicException('A sale journal entry must balance before it can be committed.');
        }
    }

    private function cents(float $amount): int
    {
        return (int) round($amount * 100);
    }
}
