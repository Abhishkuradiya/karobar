<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChartAccount;
use App\Models\ChartAccountOverride;
use App\Models\JournalLine;
use App\Support\ChartAccountCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ChartAccountsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $businessId = $request->user()->activeBusiness->id;
        $balances = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.business_id', $businessId)
            ->selectRaw('journal_lines.account_code, SUM(journal_lines.debit) as debit_total, SUM(journal_lines.credit) as credit_total, COUNT(journal_lines.id) as journal_line_count')
            ->groupBy('journal_lines.account_code')
            ->get()
            ->keyBy('account_code');
        $accounts = collect(ChartAccountCatalog::forBusiness($businessId))
            ->map(function (array $account) use ($balances): array {
                $balance = $balances->get($account['account_code']);
                $account['debit_total'] = round((float) ($balance?->debit_total ?? 0), 2);
                $account['credit_total'] = round((float) ($balance?->credit_total ?? 0), 2);
                $account['has_transactions'] = (int) ($balance?->journal_line_count ?? 0) > 0;
                $account['balance'] = round(
                    $account['normal_balance'] === 'debit'
                        ? $account['debit_total'] - $account['credit_total']
                        : $account['credit_total'] - $account['debit_total'],
                    2
                );
                $account['can_delete'] = $account['is_system']
                    ? ! $account['is_required'] && ! $account['has_transactions']
                    : ! $account['has_transactions'];

                return $account;
            })
            ->groupBy('account_type');

        return response()->json(['data' => $accounts]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'display_code' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:120'],
            'account_type' => ['required', Rule::in(['assets', 'liabilities', 'equity', 'income', 'expenses'])],
            'group_name' => ['nullable', 'string', 'max:80'],
        ]);

        $businessId = $request->user()->activeBusiness->id;
        $displayCode = trim($validated['display_code']);
        if (ChartAccount::query()->where('business_id', $businessId)->where('display_code', $displayCode)->exists()
            || collect(ChartAccountCatalog::systemAccounts())->contains('display_code', $displayCode)) {
            throw ValidationException::withMessages([
                'display_code' => ['This account code is already in use for this business.'],
            ]);
        }

        $account = ChartAccount::create([
            'user_id' => $request->user()->id,
            'business_id' => $businessId,
            'display_code' => $displayCode,
            'account_code' => 'custom_'.Str::replace('-', '', (string) Str::uuid()),
            'name' => trim($validated['name']),
            'account_type' => $validated['account_type'],
            'group_name' => trim($validated['group_name'] ?? '') ?: ucfirst($validated['account_type']),
        ]);

        return response()->json([
            'id' => $account->id,
            'display_code' => $account->display_code,
            'account_code' => $account->account_code,
            'name' => $account->name,
            'account_type' => $account->account_type,
            'group_name' => $account->group_name,
            'normal_balance' => in_array($account->account_type, ['assets', 'expenses'], true) ? 'debit' : 'credit',
            'is_system' => false,
            'is_required' => false,
            'has_transactions' => false,
            'can_delete' => true,
            'debit_total' => 0,
            'credit_total' => 0,
            'balance' => 0,
        ], 201);
    }

    public function update(Request $request, string $accountCode): JsonResponse
    {
        $user = $request->user();
        $businessId = $user->activeBusiness->id;
        $systemAccount = collect(ChartAccountCatalog::systemAccounts())
            ->first(fn (array $account): bool => $account['account_code'] === $accountCode);

        if ($systemAccount) {
            $validated = $request->validate([
                'name' => ['required', 'string', 'max:120'],
                'group_name' => ['required', 'string', 'max:80'],
                'display_code' => ['prohibited'],
                'account_type' => ['prohibited'],
            ]);
            $hasTransactions = $this->hasJournalLines($businessId, $accountCode);
            ChartAccountOverride::updateOrCreate(
                ['business_id' => $businessId, 'account_code' => $accountCode],
                [
                    'user_id' => $user->id,
                    'name' => trim($validated['name']),
                    'group_name' => trim($validated['group_name']),
                    'is_hidden' => false,
                ]
            );

            return response()->json($this->systemAccountData($systemAccount, $businessId, $hasTransactions));
        }

        $account = ChartAccount::query()
            ->where('business_id', $businessId)
            ->where('account_code', $accountCode)
            ->firstOrFail();
        $validated = $request->validate([
            'display_code' => [
                'required',
                'string',
                'max:20',
                Rule::unique('chart_accounts', 'display_code')
                    ->where('business_id', $businessId)
                    ->ignore($account->id),
            ],
            'name' => ['required', 'string', 'max:120'],
            'account_type' => ['required', Rule::in(['assets', 'liabilities', 'equity', 'income', 'expenses'])],
            'group_name' => ['required', 'string', 'max:80'],
        ]);
        if (collect(ChartAccountCatalog::systemAccounts())->contains('display_code', trim($validated['display_code']))) {
            throw ValidationException::withMessages([
                'display_code' => ['This account code is reserved for a system account.'],
            ]);
        }
        $hasTransactions = $this->hasJournalLines($businessId, $accountCode);
        if ($hasTransactions && $validated['account_type'] !== $account->account_type) {
            throw ValidationException::withMessages([
                'account_type' => ['The account type cannot be changed after journal entries have been posted.'],
            ]);
        }

        $account->update([
            'display_code' => trim($validated['display_code']),
            'name' => trim($validated['name']),
            'account_type' => $validated['account_type'],
            'group_name' => trim($validated['group_name']),
        ]);

        return response()->json($this->customAccountData($account->fresh(), $hasTransactions));
    }

    public function destroy(Request $request, string $accountCode): JsonResponse
    {
        $businessId = $request->user()->activeBusiness->id;
        $systemAccount = collect(ChartAccountCatalog::systemAccounts())
            ->first(fn (array $account): bool => $account['account_code'] === $accountCode);

        if ($systemAccount) {
            if (ChartAccountCatalog::isRequiredSystemAccount($accountCode)) {
                throw ValidationException::withMessages([
                    'account' => ['This system account is required by the app’s accounting workflows and cannot be deleted.'],
                ]);
            }
            if ($this->hasJournalLines($businessId, $accountCode)) {
                throw ValidationException::withMessages([
                    'account' => ['An account with journal history cannot be deleted.'],
                ]);
            }
            ChartAccountOverride::updateOrCreate(
                ['business_id' => $businessId, 'account_code' => $accountCode],
                ['user_id' => $request->user()->id, 'is_hidden' => true]
            );

            return response()->json(null, 204);
        }

        $account = ChartAccount::query()
            ->where('business_id', $businessId)
            ->where('account_code', $accountCode)
            ->firstOrFail();
        if ($this->hasJournalLines($businessId, $accountCode)) {
            throw ValidationException::withMessages([
                'account' => ['An account with journal history cannot be deleted.'],
            ]);
        }
        $account->delete();

        return response()->json(null, 204);
    }

    private function hasJournalLines(int $businessId, string $accountCode): bool
    {
        return JournalLine::query()
            ->where('account_code', $accountCode)
            ->whereHas('journalEntry', fn ($query) => $query->where('business_id', $businessId))
            ->exists();
    }

    private function customAccountData(ChartAccount $account, bool $hasTransactions): array
    {
        $debitTotal = (float) JournalLine::query()
            ->where('account_code', $account->account_code)
            ->whereHas('journalEntry', fn ($query) => $query->where('business_id', $account->business_id))
            ->sum('debit');
        $creditTotal = (float) JournalLine::query()
            ->where('account_code', $account->account_code)
            ->whereHas('journalEntry', fn ($query) => $query->where('business_id', $account->business_id))
            ->sum('credit');
        $normalBalance = in_array($account->account_type, ['assets', 'expenses'], true) ? 'debit' : 'credit';

        return [
            'id' => $account->id,
            'display_code' => $account->display_code,
            'account_code' => $account->account_code,
            'name' => $account->name,
            'account_type' => $account->account_type,
            'group_name' => $account->group_name,
            'normal_balance' => $normalBalance,
            'is_system' => false,
            'is_required' => false,
            'has_transactions' => $hasTransactions,
            'can_delete' => ! $hasTransactions,
            'debit_total' => round($debitTotal, 2),
            'credit_total' => round($creditTotal, 2),
            'balance' => round($normalBalance === 'debit' ? $debitTotal - $creditTotal : $creditTotal - $debitTotal, 2),
        ];
    }

    private function systemAccountData(array $account, int $businessId, bool $hasTransactions): array
    {
        $customized = collect(ChartAccountCatalog::forBusiness($businessId))
            ->firstWhere('account_code', $account['account_code']);
        if ($customized) {
            $account = array_replace($account, $customized);
        }
        $balance = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.business_id', $businessId)
            ->where('journal_lines.account_code', $account['account_code'])
            ->selectRaw('SUM(journal_lines.debit) as debit_total, SUM(journal_lines.credit) as credit_total')
            ->first();
        $debitTotal = round((float) ($balance?->debit_total ?? 0), 2);
        $creditTotal = round((float) ($balance?->credit_total ?? 0), 2);

        return [
            ...$account,
            'has_transactions' => $hasTransactions,
            'can_delete' => ! $account['is_required'] && ! $hasTransactions,
            'debit_total' => $debitTotal,
            'credit_total' => $creditTotal,
            'balance' => round(
                $account['normal_balance'] === 'debit'
                    ? $debitTotal - $creditTotal
                    : $creditTotal - $debitTotal,
                2
            ),
        ];
    }
}
