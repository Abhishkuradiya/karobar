<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\ScanBill;
use App\Models\Supplier;
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

class ExpenseController extends Controller
{
    private const CATEGORIES = [
        'rent',
        'electricity',
        'salary',
        'fuel',
        'transport',
        'internet',
        'office_expenses',
        'repairs',
        'advertising',
        'travel',
        'bank_charges',
        'miscellaneous',
        'insurance',
        'telephone',
        'maintenance',
        'professional_fees',
        'packaging',
        'taxes_licenses',
        'other',
    ];

    private const PAYMENT_METHODS = ['cash', 'upi', 'bank', 'card', 'unpaid'];

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

        $baseQuery = Expense::query()
            ->where('user_id', $user->id)
            ->where('business_id', $businessId)
            ->when($validated['from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('expense_date', '>=', $date))
            ->when($validated['to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('expense_date', '<=', $date))
            ->when($validated['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('expense_number', 'like', "%{$search}%")
                        ->orWhere('category', 'like', "%{$search}%")
                        ->orWhere('note', 'like', "%{$search}%")
                        ->orWhere('supplier_name_snapshot', 'like', "%{$search}%");
                });
            });

        $expenses = (clone $baseQuery)
            ->with(['supplier', 'scanBill:id,original_name,status'])
            ->orderByDesc('expense_date')
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 15)
            ->withQueryString();

        return response()->json([
            'data' => $expenses->items(),
            'meta' => [
                'current_page' => $expenses->currentPage(),
                'last_page' => $expenses->lastPage(),
                'per_page' => $expenses->perPage(),
                'total' => $expenses->total(),
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

        $expense = DB::transaction(function () use ($validated, $calculated, $user, $businessId): Expense {
            $supplier = $this->lockSupplier($user, $businessId, $validated['supplier_id'] ?? null);
            $scanBill = $this->lockScanBill($user, $businessId, $validated['scan_bill_id'] ?? null);
            $expense = $user->expenses()->create([
                'business_id' => $businessId,
                ...$calculated,
                'supplier_id' => $supplier?->id,
                'bank_account_id' => $validated['bank_account_id'] ?? null,
                'scan_bill_id' => $scanBill?->id,
                'supplier_name_snapshot' => $supplier?->name,
                'category' => $validated['category'],
                'expense_date' => $validated['expense_date'],
                'payment_method' => $validated['payment_method'],
                'claim_input_tax_credit' => $validated['claim_input_tax_credit'],
                'note' => $validated['note'] ?? null,
                'expense_number' => 'TMP-'.Str::uuid(),
            ]);

            $expense->update([
                'expense_number' => sprintf(
                    'EXP-%s-%06d',
                    Carbon::parse($expense->expense_date)->format('Ymd'),
                    $expense->id
                ),
            ]);
            $this->postJournal($expense);
            $scanBill?->update(['status' => 'recorded']);

            return $expense->fresh(['supplier', 'scanBill']);
        }, 3);

        return response()->json($expense, 201);
    }

    public function show(Request $request, int $expense): JsonResponse
    {
        return response()->json($this->ownedExpense($request, $expense)->load(['supplier', 'scanBill']));
    }

    public function update(Request $request, int $expense): JsonResponse
    {
        $existing = $this->ownedExpense($request, $expense);
        $validated = $request->validate($this->rules($request, $existing));
        $calculated = $this->calculate($validated);
        $user = $request->user();
        $businessId = $user->activeBusiness->id;

        $record = DB::transaction(function () use ($expense, $validated, $calculated, $user, $businessId): Expense {
            $record = Expense::query()
                ->where('user_id', $user->id)
                ->where('business_id', $businessId)
                ->lockForUpdate()
                ->findOrFail($expense);
            $supplier = $this->lockSupplier($user, $businessId, $validated['supplier_id'] ?? null);
            $scanBillId = $validated['scan_bill_id'] ?? $record->scan_bill_id;
            $scanBill = $this->lockScanBill($user, $businessId, $scanBillId, $record);

            $this->reverseCurrentJournal($record);
            if ($record->scan_bill_id && (int) $record->scan_bill_id !== $scanBill?->id) {
                ScanBill::query()
                    ->where('user_id', $user->id)
                    ->where('business_id', $businessId)
                    ->whereKey($record->scan_bill_id)
                    ->lockForUpdate()
                    ->firstOrFail()
                    ->update(['status' => 'needs_review']);
            }

            $record->update([
                ...$calculated,
                'supplier_id' => $supplier?->id,
                'bank_account_id' => $validated['bank_account_id'] ?? null,
                'scan_bill_id' => $scanBill?->id,
                'supplier_name_snapshot' => $supplier?->name,
                'category' => $validated['category'],
                'expense_date' => $validated['expense_date'],
                'payment_method' => $validated['payment_method'],
                'claim_input_tax_credit' => $validated['claim_input_tax_credit'],
                'note' => $validated['note'] ?? null,
            ]);
            $this->postJournal($record);
            $scanBill?->update(['status' => 'recorded']);

            return $record->fresh(['supplier', 'scanBill']);
        }, 3);

        return response()->json($record);
    }

    public function destroy(Request $request, int $expense): JsonResponse
    {
        $user = $request->user();
        $businessId = $user->activeBusiness->id;

        DB::transaction(function () use ($user, $businessId, $expense): void {
            $record = Expense::query()
                ->where('user_id', $user->id)
                ->where('business_id', $businessId)
                ->lockForUpdate()
                ->findOrFail($expense);
            $this->reverseCurrentJournal($record);

            if ($record->scan_bill_id) {
                ScanBill::query()
                    ->where('user_id', $user->id)
                    ->where('business_id', $businessId)
                    ->whereKey($record->scan_bill_id)
                    ->lockForUpdate()
                    ->firstOrFail()
                    ->update(['status' => 'needs_review']);
                $record->update(['scan_bill_id' => null]);
            }

            $record->delete();
        }, 3);

        return response()->json(null, 204);
    }

    private function rules(Request $request, ?Expense $expense = null): array
    {
        $user = $request->user();
        $businessId = $user->activeBusiness->id;
        $currentScanBillId = $expense?->scan_bill_id;
        $scanBillRule = Rule::exists('scan_bills', 'id')
            ->where(function (QueryBuilder $query) use ($user, $businessId, $currentScanBillId): void {
                $query->where('user_id', $user->id)
                    ->where('business_id', $businessId)
                    ->where(function (QueryBuilder $query) use ($currentScanBillId): void {
                        $query->where('status', 'needs_review');
                        if ($currentScanBillId) {
                            $query->orWhere('id', $currentScanBillId);
                        }
                    });
            });

        return [
            'category' => ['required', Rule::in(self::CATEGORIES)],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999.99', 'decimal:0,2'],
            'gst_rate' => ['required', 'numeric', 'in:0,5,12,18,28'],
            'claim_input_tax_credit' => ['required', 'boolean'],
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
            'expense_date' => ['required', 'date_format:Y-m-d'],
            'supplier_id' => [
                'nullable',
                'integer',
                Rule::exists('suppliers', 'id')->where(fn (QueryBuilder $query) => $query
                    ->where('user_id', $user->id)
                    ->where('business_id', $businessId)
                    ->whereNull('deleted_at')),
            ],
            'scan_bill_id' => ['nullable', 'integer', $scanBillRule],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    private function calculate(array $validated): array
    {
        $amount = round((float) $validated['amount'], 2);
        $gstRate = (float) $validated['gst_rate'];
        $taxAmount = round($amount * $gstRate / 100, 2);
        $total = round($amount + $taxAmount, 2);
        $isUnpaid = $validated['payment_method'] === 'unpaid';

        if ($validated['claim_input_tax_credit'] && $gstRate === 0.0) {
            throw ValidationException::withMessages([
                'claim_input_tax_credit' => ['Select a GST rate before claiming input tax credit.'],
            ]);
        }

        return [
            'amount' => $amount,
            'gst_rate' => $gstRate,
            'tax_amount' => $taxAmount,
            'total' => $total,
            'amount_paid' => $isUnpaid ? 0 : $total,
            'balance_due' => $isUnpaid ? $total : 0,
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

    private function lockScanBill(
        User $user,
        int $businessId,
        ?int $scanBillId,
        ?Expense $expense = null
    ): ?ScanBill {
        if (! $scanBillId) {
            return null;
        }

        $scanBill = ScanBill::query()
            ->where('user_id', $user->id)
            ->where('business_id', $businessId)
            ->lockForUpdate()
            ->findOrFail($scanBillId);

        if ($scanBill->status !== 'needs_review' && (int) $expense?->scan_bill_id !== $scanBill->id) {
            throw ValidationException::withMessages([
                'scan_bill_id' => ['This bill has already been recorded as an expense.'],
            ]);
        }

        return $scanBill;
    }

    private function ownedExpense(Request $request, int $id): Expense
    {
        return Expense::query()
            ->where('user_id', $request->user()->id)
            ->where('business_id', $request->user()->activeBusiness->id)
            ->findOrFail($id);
    }

    private function postJournal(Expense $expense): void
    {
        $creditAccount = match ($expense->payment_method) {
            'cash' => 'cash',
            'upi' => 'upi_clearing',
            'bank' => 'bank',
            'card' => 'card_clearing',
            'unpaid' => 'accounts_payable',
        };
        $expenseAccount = 'expense_'.$expense->category;
        $entry = JournalEntry::create([
            'user_id' => $expense->user_id,
            'business_id' => $expense->business_id,
            'entry_date' => $expense->expense_date,
            'source_type' => 'expense',
            'source_id' => $expense->id,
            'description' => "Expense {$expense->expense_number} - ".str_replace('_', ' ', $expense->category),
        ]);

        $lines = [[
            'account_code' => $expenseAccount,
            'debit' => $expense->claim_input_tax_credit ? $expense->amount : $expense->total,
            'credit' => 0,
        ], [
            'account_code' => $creditAccount,
            'bank_account_id' => $expense->payment_method === 'bank' ? $expense->bank_account_id : null,
            'debit' => 0,
            'credit' => $expense->total,
        ]];

        if ($expense->claim_input_tax_credit && (float) $expense->tax_amount > 0) {
            $lines[] = [
                'account_code' => 'input_gst',
                'debit' => $expense->tax_amount,
                'credit' => 0,
            ];
        }

        $entry->lines()->createMany($lines);
        $this->assertJournalBalances($entry);
        $expense->update(['current_journal_entry_id' => $entry->id]);
    }

    private function reverseCurrentJournal(Expense $expense): void
    {
        if (! $expense->current_journal_entry_id) {
            return;
        }

        $original = JournalEntry::query()
            ->where('user_id', $expense->user_id)
            ->where('business_id', $expense->business_id)
            ->with('lines')
            ->findOrFail($expense->current_journal_entry_id);
        $reversal = JournalEntry::create([
            'user_id' => $expense->user_id,
            'business_id' => $expense->business_id,
            'entry_date' => now()->toDateString(),
            'source_type' => 'expense_reversal',
            'source_id' => $expense->id,
            'description' => "Reversal of expense {$expense->expense_number}",
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
        $expense->update(['current_journal_entry_id' => null]);
    }

    private function assertJournalBalances(JournalEntry $entry): void
    {
        $totals = $entry->lines()
            ->selectRaw('SUM(debit) as debits, SUM(credit) as credits')
            ->first();

        if ($this->cents((float) $totals->debits) !== $this->cents((float) $totals->credits)) {
            throw new LogicException('An expense journal entry must balance before it can be committed.');
        }
    }

    private function cents(float $amount): int
    {
        return (int) round($amount * 100);
    }
}
