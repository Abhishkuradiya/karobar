<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Expense;
use App\Models\Purchase;
use App\Models\Sale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $businessId = $user->activeBusiness->id;
        $today = now()->toDateString();

        return response()->json([
            'today_sales' => $user->sales()
                ->where('business_id', $businessId)
                ->whereDate('sale_date', $today)
                ->sum('total'),
            'today_count' => $user->sales()
                ->where('business_id', $businessId)
                ->whereDate('sale_date', $today)
                ->count(),
            'outstanding_balance' => $user->sales()
                ->where('business_id', $businessId)
                ->where('payment_method', 'credit')
                ->sum('balance_due'),
            'customer_count' => $user->customers()->where('business_id', $businessId)->count(),
            'today_purchases' => $user->purchases()
                ->where('business_id', $businessId)
                ->whereDate('purchase_date', $today)
                ->sum('total'),
            'today_purchase_count' => $user->purchases()
                ->where('business_id', $businessId)
                ->whereDate('purchase_date', $today)
                ->count(),
            'supplier_payables' => $user->purchases()
                ->where('business_id', $businessId)
                ->sum('balance_due') + $user->expenses()
                ->where('business_id', $businessId)
                ->whereNotNull('supplier_id')
                ->sum('balance_due'),
            'supplier_count' => $user->suppliers()->where('business_id', $businessId)->count(),
            'today_expenses' => $user->expenses()
                ->where('business_id', $businessId)
                ->whereDate('expense_date', $today)
                ->sum('total'),
            'today_expense_count' => $user->expenses()
                ->where('business_id', $businessId)
                ->whereDate('expense_date', $today)
                ->count(),
        ]);
    }

    public function todayNetSales(Request $request): JsonResponse
    {
        $businessId = $request->user()->activeBusiness->id;
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();
        $sales = Sale::query()->where('user_id', $request->user()->id)->where('business_id', $businessId);
        $todayTotal = (float) (clone $sales)->whereDate('sale_date', $today)->sum('total');
        $yesterdayTotal = (float) (clone $sales)->whereDate('sale_date', $yesterday)->sum('total');
        // A zero baseline has no mathematical percentage denominator. For the
        // dashboard comparison, treat new sales as a full 100% increase so the
        // card always communicates a numeric movement instead of showing a
        // non-comparable text state.
        $change = $yesterdayTotal == 0.0
            ? ($todayTotal > 0 ? 100.0 : 0.0)
            : round(abs((($todayTotal - $yesterdayTotal) / $yesterdayTotal) * 100), 2);
        $comparison = $yesterdayTotal == 0.0
            ? ($todayTotal > 0 ? 'new_sales' : 'no_change')
            : ($todayTotal > $yesterdayTotal ? 'increase' : ($todayTotal < $yesterdayTotal ? 'decrease' : 'no_change'));
        $comparisonLabel = match ($comparison) {
            'increase' => '↑ '.number_format($change, 2).'%',
            'decrease' => '↓ '.number_format($change, 2).'%',
            'new_sales' => '↑ '.number_format($change, 2).'%',
            default => '0%',
        };
        $comparisonDetail = match ($comparison) {
            'increase' => 'Sales increased by '.number_format($change, 2).'% vs yesterday',
            'decrease' => 'Sales decreased by '.number_format($change, 2).'% vs yesterday',
            'new_sales' => 'Sales increased by '.number_format($change, 2).'% vs yesterday',
            default => 'No change from yesterday',
        };

        return response()->json([
            'date' => $today,
            'today_sales' => round($todayTotal, 2),
            'yesterday_sales' => round($yesterdayTotal, 2),
            'difference' => round($todayTotal - $yesterdayTotal, 2),
            'difference_percent' => $change,
            'comparison' => $comparison,
            'comparison_label' => $comparisonLabel,
            'comparison_detail' => $comparisonDetail,
        ]);
    }

    public function recentTransactions(Request $request): JsonResponse
    {
        $validated = $request->validate(['limit' => ['nullable', 'integer', 'min:1', 'max:50']]);
        $user = $request->user();
        $businessId = $user->activeBusiness->id;
        $limit = $validated['limit'] ?? 10;
        $transactions = collect()
            ->merge(Sale::query()->where('user_id', $user->id)->where('business_id', $businessId)->latest('created_at')->limit($limit)->get()->map(fn (Sale $sale): array => [
                'id' => $sale->id, 'type' => 'sale', 'title' => 'Sale', 'reference' => $sale->invoice_number,
                'party' => $sale->customer_name_snapshot, 'date' => $sale->sale_date->format('Y-m-d'), 'amount' => (float) $sale->total, 'created_at' => $sale->created_at,
            ]))
            ->merge(Purchase::query()->where('user_id', $user->id)->where('business_id', $businessId)->latest('created_at')->limit($limit)->get()->map(fn (Purchase $purchase): array => [
                'id' => $purchase->id, 'type' => 'purchase', 'title' => 'Purchase', 'reference' => $purchase->purchase_number,
                'party' => $purchase->supplier_name_snapshot, 'date' => $purchase->purchase_date->format('Y-m-d'), 'amount' => (float) $purchase->total, 'created_at' => $purchase->created_at,
            ]))
            ->merge(Expense::query()->where('user_id', $user->id)->where('business_id', $businessId)->latest('created_at')->limit($limit)->get()->map(fn (Expense $expense): array => [
                'id' => $expense->id, 'type' => 'expense', 'title' => 'Expense', 'reference' => $expense->expense_number,
                'party' => $expense->category, 'date' => $expense->expense_date->format('Y-m-d'), 'amount' => (float) $expense->total, 'created_at' => $expense->created_at,
            ]))
            ->sortByDesc('created_at')->take($limit)->values()
            ->map(fn (array $item): array => [...$item, 'amount' => round($item['amount'], 2), 'created_at' => $item['created_at']->toISOString()]);

        return response()->json(['data' => $transactions, 'meta' => ['limit' => $limit, 'total' => $transactions->count()]]);
    }
}
