<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChartAccount;
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
            ->selectRaw('journal_lines.account_code, SUM(journal_lines.debit) as debit_total, SUM(journal_lines.credit) as credit_total')
            ->groupBy('journal_lines.account_code')
            ->get()
            ->keyBy('account_code');
        $accounts = collect(ChartAccountCatalog::forBusiness($businessId))
            ->map(function (array $account) use ($balances): array {
                $balance = $balances->get($account['account_code']);
                $account['debit_total'] = round((float) ($balance?->debit_total ?? 0), 2);
                $account['credit_total'] = round((float) ($balance?->credit_total ?? 0), 2);
                $account['balance'] = round(
                    $account['normal_balance'] === 'debit'
                        ? $account['debit_total'] - $account['credit_total']
                        : $account['credit_total'] - $account['debit_total'],
                    2
                );

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
            'debit_total' => 0,
            'credit_total' => 0,
            'balance' => 0,
        ], 201);
    }
}
