<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
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
}
