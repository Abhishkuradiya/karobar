<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Support\ChartAccountCatalog;
use App\Services\JournalPostingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class JournalController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'account_code' => ['nullable', 'string', 'max:40'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        if (! empty($validated['from']) && ! empty($validated['to']) && $validated['from'] > $validated['to']) {
            throw ValidationException::withMessages(['to' => ['The end date must be on or after the start date.']]);
        }

        $businessId = $request->user()->activeBusiness->id;
        $query = JournalEntry::query()
            ->where('business_id', $businessId)
            ->when($validated['from'] ?? null, fn (Builder $builder, string $date) => $builder->whereDate('entry_date', '>=', $date))
            ->when($validated['to'] ?? null, fn (Builder $builder, string $date) => $builder->whereDate('entry_date', '<=', $date))
            ->when($validated['account_code'] ?? null, function (Builder $builder, string $accountCode): void {
                $builder->whereHas('lines', fn (Builder $lines) => $lines->where('account_code', $accountCode));
            });
        if (! empty($validated['search'])) {
            $query->where(function (Builder $builder) use ($validated): void {
                $builder->where('description', 'like', '%'.$validated['search'].'%')
                    ->orWhere('source_type', 'like', '%'.$validated['search'].'%');
            });
        }
        $entries = (clone $query)
            ->with('lines')
            ->withSum('lines as debit_total', 'debit')
            ->withSum('lines as credit_total', 'credit')
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 50)
            ->withQueryString();
        $entryIds = (clone $query)->select('journal_entries.id');
        $lines = JournalLine::query()->whereIn('journal_entry_id', $entryIds);

        return response()->json([
            'data' => $entries->items(),
            'meta' => [
                'current_page' => $entries->currentPage(),
                'last_page' => $entries->lastPage(),
                'per_page' => $entries->perPage(),
                'total' => $entries->total(),
            ],
            'summary' => [
                'count' => (clone $query)->count(),
                'debits' => round((float) (clone $lines)->sum('debit'), 2),
                'credits' => round((float) (clone $lines)->sum('credit'), 2),
            ],
        ]);
    }

    public function store(Request $request, JournalPostingService $posting): JsonResponse
    {
        $validated = $request->validate([
            'entry_date' => ['required', 'date_format:Y-m-d'],
            'narration' => ['required', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:2', 'max:50'],
            'lines.*.account_code' => ['required', 'string', 'max:40'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
        ]);
        $businessId = $request->user()->activeBusiness->id;
        $lines = [];
        $debits = 0.0;
        $credits = 0.0;

        foreach ($validated['lines'] as $index => $line) {
            if (! ChartAccountCatalog::resolve($line['account_code'], $businessId)) {
                throw ValidationException::withMessages([
                    "lines.{$index}.account_code" => ['Choose an account from this business chart of accounts.'],
                ]);
            }
            $debit = round((float) ($line['debit'] ?? 0), 2);
            $credit = round((float) ($line['credit'] ?? 0), 2);
            if (($debit > 0) === ($credit > 0)) {
                throw ValidationException::withMessages([
                    "lines.{$index}.debit" => ['Each line must contain either a debit or a credit amount, but not both.'],
                ]);
            }
            $debits += $debit;
            $credits += $credit;
            $lines[] = ['account_code' => $line['account_code'], 'debit' => $debit, 'credit' => $credit];
        }
        if ((int) round($debits * 100) !== (int) round($credits * 100)) {
            throw ValidationException::withMessages(['lines' => ['Total debits must equal total credits.']]);
        }

        $entry = \Illuminate\Support\Facades\DB::transaction(fn () => $posting->post(
            $request->user()->id,
            $businessId,
            $validated['entry_date'],
            'manual_adjustment',
            trim($validated['narration']),
            $lines
        ), 3);

        return response()->json($entry, 201);
    }
}
