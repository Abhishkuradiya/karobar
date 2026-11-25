<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BankStatementTransaction;
use App\Models\CashTally;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\ScanBill;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MonthEndCloseController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $businessId = $request->user()->activeBusiness->id;
        $month = Carbon::createFromFormat('Y-m', $validated['month'] ?? now()->format('Y-m'))->startOfMonth();
        $start = $month->toDateString();
        $end = $month->copy()->endOfMonth()->toDateString();

        $bankTransactions = BankStatementTransaction::query()
            ->where('business_id', $businessId)
            ->whereBetween('transaction_date', [$start, $end])
            ->count();
        $unmatchedBankTransactions = BankStatementTransaction::query()
            ->where('business_id', $businessId)
            ->whereBetween('transaction_date', [$start, $end])
            ->whereNull('reconciled_at')
            ->count();
        $cashTallies = CashTally::query()
            ->where('business_id', $businessId)
            ->whereBetween('counted_date', [$start, $end])
            ->count();
        $scannedBills = ScanBill::query()
            ->where('business_id', $businessId)
            ->whereBetween(DB::raw('DATE(created_at)'), [$start, $end])
            ->count();
        $billsNeedingReview = ScanBill::query()
            ->where('business_id', $businessId)
            ->whereBetween(DB::raw('DATE(created_at)'), [$start, $end])
            ->where('status', 'needs_review')
            ->count();
        $missingHsn = SaleItem::query()
            ->whereNull('hsn_sac')
            ->whereHas('sale', fn ($query) => $query->where('business_id', $businessId)->whereBetween('sale_date', [$start, $end]))
            ->count();
        $negativeStock = Product::query()
            ->where('business_id', $businessId)
            ->where('current_stock', '<', 0)
            ->count();
        $overdueSales = Sale::query()
            ->where('business_id', $businessId)
            ->where('balance_due', '>', 0)
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<=', $end)
            ->count();
        $overdueMsmePurchases = Purchase::query()
            ->where('business_id', $businessId)
            ->where('balance_due', '>', 0)
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<=', $end)
            ->whereHas('supplier', fn ($query) => $query->whereIn('msme_type', ['micro', 'small']))
            ->count();

        $items = [
            [
                'key' => 'bank_reconciliation',
                'title' => 'Match bank transactions',
                'description' => $unmatchedBankTransactions
                    ? "{$unmatchedBankTransactions} imported transactions are still unmatched."
                    : ($bankTransactions ? 'All imported transactions for this month are matched.' : 'No bank statements imported for this month.'),
                'status' => $unmatchedBankTransactions ? 'needs_attention' : ($bankTransactions ? 'done' : 'not_started'),
                'count' => $unmatchedBankTransactions,
                'href' => '/banking',
            ],
            [
                'key' => 'cash_tally',
                'title' => 'Complete cash tally',
                'description' => $cashTallies ? "{$cashTallies} cash tally recorded this month." : 'Record the physical cash count for this month.',
                'status' => $cashTallies ? 'done' : 'not_started',
                'count' => $cashTallies,
                'href' => '/cash-tally',
            ],
            [
                'key' => 'scanned_bills',
                'title' => 'Review scanned bills',
                'description' => $billsNeedingReview
                    ? "{$billsNeedingReview} scanned bills still need review."
                    : ($scannedBills ? 'All scanned bills have been reviewed.' : 'No scanned bills for this month.'),
                'status' => $billsNeedingReview ? 'needs_attention' : ($scannedBills ? 'done' : 'not_started'),
                'count' => $billsNeedingReview,
                'href' => '/scan-bills',
            ],
            [
                'key' => 'gst',
                'title' => 'Review GST return',
                'description' => $missingHsn
                    ? "{$missingHsn} sales line items are missing an HSN/SAC code."
                    : 'Review the GST summary and verify the period before filing.',
                'status' => $missingHsn ? 'needs_attention' : 'action_required',
                'count' => $missingHsn,
                'href' => '/gst',
            ],
            [
                'key' => 'inventory',
                'title' => 'Check stock balances',
                'description' => $negativeStock ? "{$negativeStock} products have negative stock." : 'No products have negative stock.',
                'status' => $negativeStock ? 'needs_attention' : 'done',
                'count' => $negativeStock,
                'href' => '/inventory',
            ],
            [
                'key' => 'customer_overdues',
                'title' => 'Follow up overdue customers',
                'description' => $overdueSales ? "{$overdueSales} sales invoices have an outstanding overdue balance." : 'No overdue customer invoices.',
                'status' => $overdueSales ? 'needs_attention' : 'done',
                'count' => $overdueSales,
                'href' => '/invoices',
            ],
            [
                'key' => 'msme_payables',
                'title' => 'Review overdue MSME suppliers',
                'description' => $overdueMsmePurchases ? "{$overdueMsmePurchases} overdue purchases are payable to micro or small suppliers." : 'No overdue MSME supplier purchases.',
                'status' => $overdueMsmePurchases ? 'needs_attention' : 'done',
                'count' => $overdueMsmePurchases,
                'href' => '/purchases',
            ],
        ];
        $done = collect($items)->where('status', 'done')->count();

        return response()->json([
            'month' => $month->format('Y-m'),
            'period' => ['from' => $start, 'to' => $end],
            'progress' => ['completed' => $done, 'total' => count($items), 'percentage' => (int) round($done / count($items) * 100)],
            'data' => $items,
        ]);
    }
}
