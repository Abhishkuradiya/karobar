<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SupplierController extends Controller
{
    private const MSME_TYPES = ['not_known', 'micro', 'small', 'medium'];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $businessId = $user->activeBusiness->id;
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $baseQuery = Supplier::query()
            ->where('user_id', $user->id)
            ->where('business_id', $businessId)
            ->when($validated['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('mobile', 'like', "%{$search}%")
                        ->orWhere('gstin', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('udyam_number', 'like', "%{$search}%");
                });
            });

        $suppliers = (clone $baseQuery)
            ->withSum(['purchases as purchase_balance_due' => fn (Builder $query) => $query->where('business_id', $businessId)], 'balance_due')
            ->withSum(['expenses as expense_balance_due' => fn (Builder $query) => $query->where('business_id', $businessId)], 'balance_due')
            ->withCount(['purchases' => fn (Builder $query) => $query->where('business_id', $businessId)])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 15)
            ->withQueryString();

        foreach ($suppliers->items() as $supplier) {
            $supplier->setAttribute(
                'outstanding_balance',
                round((float) $supplier->purchase_balance_due + (float) $supplier->expense_balance_due, 2)
            );
        }

        return response()->json([
            'data' => $suppliers->items(),
            'meta' => [
                'current_page' => $suppliers->currentPage(),
                'last_page' => $suppliers->lastPage(),
                'per_page' => $suppliers->perPage(),
                'total' => $suppliers->total(),
            ],
            'summary' => [
                'count' => (clone $baseQuery)->count(),
                'outstanding' => $user->purchases()->where('business_id', $businessId)->sum('balance_due')
                    + $user->expenses()->where('business_id', $businessId)->whereNotNull('supplier_id')->sum('balance_due'),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->normalizeIdentifiers($request);
        $validated = $request->validate($this->rules($request));

        $supplier = $request->user()->suppliers()->create([
            ...$validated,
            'business_id' => $request->user()->activeBusiness->id,
        ]);

        return response()->json($this->withBalances($supplier), 201);
    }

    public function show(Request $request, int $supplier): JsonResponse
    {
        return response()->json($this->withBalances($this->ownedSupplier($request, $supplier)));
    }

    public function update(Request $request, int $supplier): JsonResponse
    {
        $record = $this->ownedSupplier($request, $supplier);
        $this->normalizeIdentifiers($request);
        $validated = $request->validate($this->rules($request, $record));
        $record->update($validated);

        return response()->json($this->withBalances($record->refresh()));
    }

    public function destroy(Request $request, int $supplier): JsonResponse
    {
        $this->ownedSupplier($request, $supplier)->delete();

        return response()->json(null, 204);
    }

    private function normalizeIdentifiers(Request $request): void
    {
        if (is_string($request->input('name'))) {
            $request->merge(['name' => trim($request->input('name'))]);
        }
        foreach (['gstin', 'udyam_number'] as $field) {
            if (is_string($request->input($field))) {
                $request->merge([$field => strtoupper(trim($request->input($field))) ?: null]);
            }
        }
        if (is_string($request->input('mobile'))) {
            $request->merge(['mobile' => trim($request->input('mobile')) ?: null]);
        }
        if (is_string($request->input('email'))) {
            $request->merge(['email' => strtolower(trim($request->input('email'))) ?: null]);
        }
    }

    private function ownedSupplier(Request $request, int $id): Supplier
    {
        return Supplier::query()
            ->where('user_id', $request->user()->id)
            ->where('business_id', $request->user()->activeBusiness->id)
            ->findOrFail($id);
    }

    private function withBalances(Supplier $supplier): Supplier
    {
        $businessId = $supplier->business_id;
        $supplier
            ->loadSum(['purchases as purchase_balance_due' => fn (Builder $query) => $query->where('business_id', $businessId)], 'balance_due')
            ->loadSum(['expenses as expense_balance_due' => fn (Builder $query) => $query->where('business_id', $businessId)], 'balance_due')
            ->loadCount(['purchases' => fn (Builder $query) => $query->where('business_id', $businessId)]);

        return $supplier->setAttribute(
            'outstanding_balance',
            round((float) $supplier->purchase_balance_due + (float) $supplier->expense_balance_due, 2)
        );
    }

    private function rules(Request $request, ?Supplier $supplier = null): array
    {
        $user = $request->user();
        $businessId = $user->activeBusiness->id;
        $gstinUnique = Rule::unique('suppliers', 'gstin')
            ->where(fn (QueryBuilder $query) => $query
                ->where('business_id', $businessId)
                ->whereNull('deleted_at'));

        if ($supplier) {
            $gstinUnique->ignore($supplier->id);
        }
        $mobileUnique = Rule::unique('suppliers', 'mobile')
            ->where(fn (QueryBuilder $query) => $query
                ->where('user_id', $user->id)
                ->where('business_id', $businessId)
                ->whereNull('deleted_at'));
        if ($supplier) {
            $mobileUnique->ignore($supplier->id);
        }
        $emailUnique = Rule::unique('suppliers', 'email')
            ->where(fn (QueryBuilder $query) => $query
                ->where('user_id', $user->id)
                ->whereNull('deleted_at'));
        if ($supplier) {
            $emailUnique->ignore($supplier->id);
        }
        $udyamUnique = Rule::unique('suppliers', 'udyam_number')
            ->where(fn (QueryBuilder $query) => $query
                ->where('business_id', $businessId)
                ->whereNull('deleted_at'));
        if ($supplier) {
            $udyamUnique->ignore($supplier->id);
        }

        $msmeType = $request->input('msme_type', $supplier?->msme_type ?? 'not_known');
        $maxCreditDays = in_array($msmeType, ['micro', 'small'], true) ? 45 : 3650;

        return [
            'name' => ['required', 'string', 'max:150'],
            'mobile' => ['nullable', 'digits:10', $mobileUnique],
            'credit_days' => ['required', 'integer', 'min:0', "max:{$maxCreditDays}"],
            'msme_type' => ['required', Rule::in(self::MSME_TYPES)],
            'udyam_number' => ['nullable', 'string', 'max:30', 'regex:/^UDYAM-[A-Z]{2}-\\d{2}-\\d{7}$/', $udyamUnique],
            'gstin' => [
                'nullable',
                'string',
                'size:15',
                'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/',
                $gstinUnique,
            ],
            'state' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:254', $emailUnique],
            'address' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
