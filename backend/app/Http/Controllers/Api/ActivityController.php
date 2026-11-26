<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ActivityController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        if (! empty($validated['from']) && ! empty($validated['to']) && $validated['from'] > $validated['to']) {
            throw ValidationException::withMessages(['to' => ['The end date must be on or after the start date.']]);
        }

        $entries = Activity::query()
            ->where('business_id', $request->user()->activeBusiness->id)
            ->when($validated['from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('entry_date', '>=', $date))
            ->when($validated['to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('entry_date', '<=', $date))
            ->when($validated['search'] ?? null, fn (Builder $query, string $search) => $query->where(function (Builder $query) use ($search): void {
                $query->where('description', 'like', "%{$search}%")
                    ->orWhere('source_type', 'like', "%{$search}%");
            }))
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 50)
            ->withQueryString();

        $data = collect($entries->items())->map(function (Activity $entry): array {
            $labels = [
                'sale' => 'Sale recorded',
                'sale_reversal' => 'Sale reversed',
                'purchase' => 'Purchase recorded',
                'purchase_reversal' => 'Purchase reversed',
                'expense' => 'Expense recorded',
                'expense_reversal' => 'Expense reversed',
                'credit_note' => 'Credit note recorded',
                'debit_note' => 'Debit note recorded',
                'cash_tally' => 'Cash tally adjustment',
                'manual_adjustment' => 'Journal adjustment',
                'opening_balance' => 'Opening balance imported',
                'journal_voucher' => 'Journal voucher imported',
            ];

            return [
                'id' => $entry->id,
                'title' => $labels[$entry->source_type] ?? ucfirst(str_replace('_', ' ', $entry->source_type)),
                'source_type' => $entry->source_type,
                'source_id' => $entry->source_id,
                'description' => $entry->description,
                'entry_date' => $entry->entry_date?->format('Y-m-d'),
                'created_at' => $entry->created_at,
                'user' => $entry->user?->name,
                'amount' => round((float) $entry->amount, 2),
                'is_reversal' => $entry->reversal_of_id !== null || str_ends_with($entry->source_type, '_reversal'),
            ];
        });

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $entries->currentPage(),
                'last_page' => $entries->lastPage(),
                'per_page' => $entries->perPage(),
                'total' => $entries->total(),
            ],
        ]);
    }
}
