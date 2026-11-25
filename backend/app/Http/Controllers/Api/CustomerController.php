<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $businessId = $user->activeBusiness->id;
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = Customer::query()
            ->where('user_id', $user->id)
            ->where('business_id', $businessId)
            ->withSum('sales as outstanding_balance', 'balance_due')
            ->withCount('sales')
            ->when($validated['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('mobile', 'like', "%{$search}%")
                        ->orWhere('gstin', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->orderBy('name');

        $customers = $query->paginate($validated['per_page'] ?? 15)->withQueryString();

        return response()->json([
            'data' => $customers->items(),
            'meta' => [
                'current_page' => $customers->currentPage(),
                'last_page' => $customers->lastPage(),
                'per_page' => $customers->perPage(),
                'total' => $customers->total(),
            ],
            'summary' => [
                'count' => $user->customers()->where('business_id', $businessId)->count(),
                'outstanding' => $user->sales()->where('business_id', $businessId)->sum('balance_due'),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules($request));
        if (array_key_exists('gstin', $validated) && $validated['gstin'] !== null) {
            $validated['gstin'] = strtoupper($validated['gstin']);
        }

        $customer = $request->user()->customers()->create([
            ...$validated,
            'business_id' => $request->user()->activeBusiness->id,
        ]);

        return response()->json(
            $customer->loadSum('sales as outstanding_balance', 'balance_due'),
            201
        );
    }

    public function show(Request $request, int $customer): JsonResponse
    {
        return response()->json(
            $this->ownedCustomer($request, $customer)
                ->loadSum('sales as outstanding_balance', 'balance_due')
                ->loadCount('sales')
        );
    }

    public function update(Request $request, int $customer): JsonResponse
    {
        $record = $this->ownedCustomer($request, $customer);
        $validated = $request->validate($this->rules($request, $record));
        if (array_key_exists('gstin', $validated) && $validated['gstin'] !== null) {
            $validated['gstin'] = strtoupper($validated['gstin']);
        }
        $record->update($validated);

        return response()->json(
            $record->refresh()
                ->loadSum('sales as outstanding_balance', 'balance_due')
                ->loadCount('sales')
        );
    }

    public function destroy(Request $request, int $customer): JsonResponse
    {
        $this->ownedCustomer($request, $customer)->delete();

        return response()->json(null, 204);
    }

    private function ownedCustomer(Request $request, int $id): Customer
    {
        return Customer::query()
            ->where('user_id', $request->user()->id)
            ->where('business_id', $request->user()->activeBusiness->id)
            ->findOrFail($id);
    }

    private function rules(Request $request, ?Customer $customer = null): array
    {
        $gstinUnique = Rule::unique('customers', 'gstin')
            ->where(fn (QueryBuilder $query) => $query
                ->where('business_id', $request->user()->activeBusiness->id)
                ->whereNull('deleted_at'));

        if ($customer) {
            $gstinUnique->ignore($customer->id);
        }

        return [
            'name' => ['required', 'string', 'max:150'],
            'mobile' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+()\\-\\s]{7,30}$/'],
            'credit_days' => ['required', 'integer', 'min:0', 'max:3650'],
            'credit_limit' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'gstin' => [
                'nullable',
                'string',
                'size:15',
                'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/',
                $gstinUnique,
            ],
            'state' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:254'],
            'address' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
