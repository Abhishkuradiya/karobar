<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class InvoiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', 'in:all,active,void'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        if (! empty($validated['from']) && ! empty($validated['to']) && $validated['from'] > $validated['to']) {
            throw ValidationException::withMessages(['to' => ['The end date must be on or after the start date.']]);
        }

        $query = Sale::withTrashed()
            ->where('user_id', $request->user()->id)
            ->where('business_id', $request->user()->activeBusiness->id)
            ->when(($validated['status'] ?? 'all') === 'active', fn (Builder $query) => $query->whereNull('deleted_at'))
            ->when(($validated['status'] ?? 'all') === 'void', fn (Builder $query) => $query->whereNotNull('deleted_at'))
            ->when($validated['from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('sale_date', '>=', $date))
            ->when($validated['to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('sale_date', '<=', $date))
            ->when($validated['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('invoice_number', 'like', "%{$search}%")
                        ->orWhere('customer_name_snapshot', 'like', "%{$search}%");
                });
            });
        $invoices = (clone $query)
            ->with(['customer', 'items'])
            ->orderByDesc('sale_date')
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 50)
            ->withQueryString();

        return response()->json([
            'data' => collect($invoices->items())->map(fn (Sale $sale): array => [
                ...$sale->toArray(),
                'status' => $sale->trashed() ? 'void' : ($sale->balance_due > 0 ? 'unpaid' : 'paid'),
            ]),
            'meta' => [
                'current_page' => $invoices->currentPage(),
                'last_page' => $invoices->lastPage(),
                'per_page' => $invoices->perPage(),
                'total' => $invoices->total(),
            ],
            'summary' => [
                'count' => (clone $query)->count(),
                'total' => (clone $query)->sum('total'),
                'outstanding' => (clone $query)->sum('balance_due'),
            ],
        ]);
    }

    public function show(Request $request, int $invoice): JsonResponse
    {
        $sale = Sale::withTrashed()
            ->where('user_id', $request->user()->id)
            ->where('business_id', $request->user()->activeBusiness->id)
            ->with(['customer', 'items.product'])
            ->findOrFail($invoice);

        return response()->json([
            ...$sale->toArray(),
            'status' => $sale->trashed() ? 'void' : ($sale->balance_due > 0 ? 'unpaid' : 'paid'),
        ]);
    }
}
