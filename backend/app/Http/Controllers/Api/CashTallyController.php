<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashTally;
use App\Services\JournalPostingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashTallyController extends Controller
{
    public function index(Request $request, JournalPostingService $posting): JsonResponse
    {
        $validated = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $businessId = $request->user()->activeBusiness->id;
        $date = $validated['date'] ?? now()->toDateString();
        $existing = CashTally::query()
            ->where('business_id', $businessId)
            ->whereDate('counted_date', $date)
            ->first();

        return response()->json([
            'date' => $date,
            'book_amount' => $posting->balanceForAccount($businessId, 'cash', $date),
            'tally' => $existing,
            'recent' => CashTally::query()
                ->where('business_id', $businessId)
                ->orderByDesc('counted_date')
                ->orderByDesc('id')
                ->limit(10)
                ->get(),
        ]);
    }

    public function store(Request $request, JournalPostingService $posting): JsonResponse
    {
        $validated = $request->validate([
            'counted_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'counted_amount' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $businessId = $request->user()->activeBusiness->id;
        if (CashTally::query()
            ->where('business_id', $businessId)
            ->whereDate('counted_date', $validated['counted_date'])
            ->exists()) {
            throw ValidationException::withMessages([
                'counted_date' => ['A cash tally has already been saved for this date.'],
            ]);
        }
        if (CashTally::query()
            ->where('business_id', $businessId)
            ->whereDate('counted_date', '>', $validated['counted_date'])
            ->exists()) {
            throw ValidationException::withMessages([
                'counted_date' => ['A historical tally cannot be inserted before a later saved cash count.'],
            ]);
        }

        $tally = DB::transaction(function () use ($request, $posting, $validated, $businessId): CashTally {
            $bookAmount = $posting->balanceForAccount($businessId, 'cash', $validated['counted_date']);
            $countedAmount = round((float) $validated['counted_amount'], 2);
            $difference = round($countedAmount - $bookAmount, 2);
            $tally = CashTally::create([
                'user_id' => $request->user()->id,
                'business_id' => $businessId,
                'counted_date' => $validated['counted_date'],
                'book_amount' => $bookAmount,
                'counted_amount' => $countedAmount,
                'difference' => $difference,
                'note' => isset($validated['note']) ? trim($validated['note']) : null,
            ]);

            if ($difference !== 0.0) {
                $positiveDifference = $difference > 0;
                $entry = $posting->post(
                    $request->user()->id,
                    $businessId,
                    $validated['counted_date'],
                    'cash_tally',
                    'Cash tally adjustment'.($tally->note ? ': '.$tally->note : ''),
                    [
                        [
                            'account_code' => $positiveDifference ? 'cash' : 'cash_difference',
                            'debit' => abs($difference),
                            'credit' => 0,
                        ],
                        [
                            'account_code' => $positiveDifference ? 'cash_difference' : 'cash',
                            'debit' => 0,
                            'credit' => abs($difference),
                        ],
                    ],
                    $tally->id
                );
                $tally->update(['journal_entry_id' => $entry->id]);
            }

            return $tally->fresh();
        }, 3);

        return response()->json(['data' => $tally], 201);
    }
}
