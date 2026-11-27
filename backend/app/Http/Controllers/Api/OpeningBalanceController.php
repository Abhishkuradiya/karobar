<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\JournalEntry;
use App\Support\ChartAccountCatalog;
use App\Services\JournalPostingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OpeningBalanceController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $business = $request->user()->activeBusiness;
        $openingDate = $this->openingDate();
        $accounts = collect(ChartAccountCatalog::forBusiness($business->id))
            ->filter(fn (array $account): bool => in_array($account['account_type'], ['assets', 'liabilities', 'equity'], true))
            ->values();
        $balances = DB::table('journal_entries')
            ->join('journal_lines', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entries.business_id', $business->id)
            ->where('journal_entries.source_type', 'opening_balance')
            ->select('journal_lines.account_code')
            ->selectRaw('SUM(journal_lines.debit) as debits, SUM(journal_lines.credit) as credits')
            ->groupBy('journal_lines.account_code')
            ->get()
            ->keyBy('account_code');

        return response()->json([
            'opening_date' => $openingDate,
            'accounts' => $accounts->map(function (array $account) use ($balances): array {
                $balance = $balances->get($account['account_code']);
                $debit = round((float) ($balance?->debits ?? 0), 2);
                $credit = round((float) ($balance?->credits ?? 0), 2);
                return [...$account, 'opening_amount' => $account['normal_balance'] === 'debit' ? $debit : $credit];
            })->groupBy('account_type'),
            'summary' => $this->summary($balances),
        ]);
    }

    public function store(Request $request, JournalPostingService $posting): JsonResponse
    {
        $validated = $request->validate([
            'opening_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'balances' => ['required', 'array'],
            'balances.*.account_code' => ['required', 'string', 'max:40', 'distinct'],
            'balances.*.amount' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'balances.*.side' => ['required', 'in:debit,credit'],
        ]);
        $business = $request->user()->activeBusiness;
        $lines = [];
        $totalDebits = 0.0;
        $totalCredits = 0.0;
        foreach ($validated['balances'] as $index => $balance) {
            $amount = round((float) ($balance['amount'] ?? 0), 2);
            if ($amount === 0.0) continue;
            $account = ChartAccountCatalog::resolve($balance['account_code'], $business->id);
            if (!$account || !in_array($account['account_type'], ['assets', 'liabilities', 'equity'], true)) {
                throw ValidationException::withMessages(["balances.{$index}.account_code" => ['Choose a balance-sheet account from this business chart of accounts.']]);
            }
            if ($balance['side'] === 'debit') $totalDebits += $amount; else $totalCredits += $amount;
            $lines[] = ['account_code' => $account['account_code'], 'debit' => $balance['side'] === 'debit' ? $amount : 0, 'credit' => $balance['side'] === 'credit' ? $amount : 0];
        }
        $difference = round($totalDebits - $totalCredits, 2);
        if (abs($difference) > 0.005) {
            $equity = ChartAccountCatalog::resolve('opening_balance_equity', $business->id);
            $lines[] = ['account_code' => $equity['account_code'], 'debit' => $difference < 0 ? abs($difference) : 0, 'credit' => $difference > 0 ? $difference : 0];
        }

        DB::transaction(function () use ($business, $request, $posting, $validated, $lines): void {
            JournalEntry::query()->where('business_id', $business->id)->where('source_type', 'opening_balance')->delete();
            if ($lines) $posting->post($request->user()->id, $business->id, $validated['opening_date'], 'opening_balance', 'Opening balances', $lines);
        }, 3);

        return $this->show($request);
    }

    private function openingDate(): string
    {
        $year = now()->month >= 4 ? now()->year : now()->year - 1;
        return sprintf('%d-04-01', $year);
    }

    private function summary($balances): array
    {
        $debits = round((float) $balances->sum('debits'), 2);
        $credits = round((float) $balances->sum('credits'), 2);
        return ['debits' => $debits, 'credits' => $credits, 'opening_balance_equity' => round($credits - $debits, 2)];
    }
}
