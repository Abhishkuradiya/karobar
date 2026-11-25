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
        ]);
    }
}
