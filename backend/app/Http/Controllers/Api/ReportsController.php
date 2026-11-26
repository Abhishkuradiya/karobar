<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\InventoryBatch;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Support\ChartAccountCatalog;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportsController extends Controller
{
    private const REPORTS = [
        'income-tax-compliance',
        'business-checkup',
        'cash-forecast',
        'profit-by-product',
        'profit-and-loss',
        'balance-sheet',
        'cash-flow',
        'sales',
        'purchases',
        'expenses',
        'receivables',
        'payables',
        'customer-ledgers',
        'vendor-ledgers',
        'inventory',
        'trial-balance',
        'day-book-journal',
        'account-ledgers',
    ];

    public function show(Request $request, string $report): JsonResponse
    {
        abort_unless(in_array($report, self::REPORTS, true), 404);

        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'account_code' => ['nullable', 'string', 'max:40'],
        ]);
        $today = now()->toDateString();
        $from = $validated['from'] ?? ($report === 'cash-forecast' ? $today : now()->startOfYear()->toDateString());
        $to = $validated['to'] ?? ($report === 'cash-forecast' ? now()->addDays(90)->toDateString() : $today);
        $businessId = (int) $request->user()->activeBusiness->id;

        $data = match ($report) {
            'sales' => $this->salesReport($businessId, $from, $to),
            'purchases' => $this->purchasesReport($businessId, $from, $to),
            'expenses' => $this->expensesReport($businessId, $from, $to),
            'receivables' => $this->receivablesReport($businessId, $from, $to),
            'payables' => $this->payablesReport($businessId, $from, $to),
            'customer-ledgers' => $this->customerLedgerReport($businessId, $from, $to),
            'vendor-ledgers' => $this->vendorLedgerReport($businessId, $from, $to),
            'inventory' => $this->inventoryReport($businessId),
            'profit-by-product' => $this->profitByProductReport($businessId, $from, $to),
            'trial-balance' => $this->trialBalanceReport($businessId, $to),
            'profit-and-loss', 'income-tax-compliance' => $this->profitAndLossReport($businessId, $from, $to, $report),
            'balance-sheet' => $this->balanceSheetReport($businessId, $to),
            'day-book-journal' => $this->dayBookReport($businessId, $from, $to),
            'account-ledgers' => $this->accountLedgerReport($businessId, $from, $to, $validated['account_code'] ?? null),
            'cash-flow' => $this->cashFlowReport($businessId, $from, $to),
            'cash-forecast' => $this->cashForecastReport($businessId, $from, $to),
            'business-checkup' => $this->businessCheckupReport($businessId),
        };

        return response()->json([
            'report' => $report,
            'period' => ['from' => $from, 'to' => $to],
            ...$data,
        ]);
    }

    private function salesReport(int $businessId, string $from, string $to): array
    {
        $sales = Sale::query()->with('customer:id,name')
            ->where('business_id', $businessId)
            ->whereBetween('sale_date', [$from, $to])
            ->orderByDesc('sale_date')
            ->limit(1000)
            ->get();

        return $this->table('Sales', [
            ['key' => 'reference', 'label' => 'Invoice'],
            ['key' => 'date', 'label' => 'Date'],
            ['key' => 'party', 'label' => 'Customer'],
            ['key' => 'total', 'label' => 'Total', 'numeric' => true],
            ['key' => 'paid', 'label' => 'Paid', 'numeric' => true],
            ['key' => 'balance', 'label' => 'Balance due', 'numeric' => true],
        ], $sales->map(fn (Sale $sale): array => [
            'reference' => $sale->invoice_number,
            'date' => $sale->sale_date->format('Y-m-d'),
            'party' => $sale->customer?->name ?? $sale->customer_name_snapshot,
            'total' => (float) $sale->total,
            'paid' => (float) $sale->amount_paid,
            'balance' => (float) $sale->balance_due,
        ])->all(), [
            'documents' => $sales->count(),
            'sales_total' => (float) $sales->sum('total'),
            'amount_collected' => (float) $sales->sum('amount_paid'),
            'outstanding' => (float) $sales->sum('balance_due'),
        ]);
    }

    private function purchasesReport(int $businessId, string $from, string $to): array
    {
        $purchases = Purchase::query()->with('supplier:id,name')
            ->where('business_id', $businessId)
            ->whereBetween('purchase_date', [$from, $to])
            ->orderByDesc('purchase_date')
            ->limit(1000)
            ->get();

        return $this->table('Purchases', [
            ['key' => 'reference', 'label' => 'Purchase'],
            ['key' => 'date', 'label' => 'Date'],
            ['key' => 'party', 'label' => 'Supplier'],
            ['key' => 'total', 'label' => 'Total', 'numeric' => true],
            ['key' => 'paid', 'label' => 'Paid', 'numeric' => true],
            ['key' => 'balance', 'label' => 'Balance due', 'numeric' => true],
        ], $purchases->map(fn (Purchase $purchase): array => [
            'reference' => $purchase->purchase_number,
            'date' => $purchase->purchase_date->format('Y-m-d'),
            'party' => $purchase->supplier?->name ?? $purchase->supplier_name_snapshot,
            'total' => (float) $purchase->total,
            'paid' => (float) $purchase->amount_paid,
            'balance' => (float) $purchase->balance_due,
        ])->all(), [
            'documents' => $purchases->count(),
            'purchases_total' => (float) $purchases->sum('total'),
            'amount_paid' => (float) $purchases->sum('amount_paid'),
            'outstanding' => (float) $purchases->sum('balance_due'),
        ]);
    }

    private function expensesReport(int $businessId, string $from, string $to): array
    {
        $expenses = Expense::query()->with('supplier:id,name')
            ->where('business_id', $businessId)
            ->whereBetween('expense_date', [$from, $to])
            ->orderByDesc('expense_date')
            ->limit(1000)
            ->get();

        return $this->table('Expenses', [
            ['key' => 'reference', 'label' => 'Expense'],
            ['key' => 'date', 'label' => 'Date'],
            ['key' => 'category', 'label' => 'Category'],
            ['key' => 'party', 'label' => 'Supplier'],
            ['key' => 'total', 'label' => 'Total', 'numeric' => true],
            ['key' => 'balance', 'label' => 'Balance due', 'numeric' => true],
        ], $expenses->map(fn (Expense $expense): array => [
            'reference' => $expense->expense_number,
            'date' => $expense->expense_date->format('Y-m-d'),
            'category' => str($expense->category)->replace('_', ' ')->title()->toString(),
            'party' => $expense->supplier?->name ?? $expense->supplier_name_snapshot ?? '—',
            'total' => (float) $expense->total,
            'balance' => (float) $expense->balance_due,
        ])->all(), [
            'documents' => $expenses->count(),
            'expenses_total' => (float) $expenses->sum('total'),
            'outstanding' => (float) $expenses->sum('balance_due'),
        ]);
    }

    private function receivablesReport(int $businessId, string $from, string $to): array
    {
        $sales = Sale::query()->with('customer:id,name')
            ->where('business_id', $businessId)
            ->whereBetween('sale_date', [$from, $to])
            ->where('balance_due', '>', 0)
            ->orderBy('due_date')
            ->limit(1000)
            ->get();

        return $this->table('Receivables', [
            ['key' => 'reference', 'label' => 'Invoice'],
            ['key' => 'party', 'label' => 'Customer'],
            ['key' => 'due_date', 'label' => 'Due date'],
            ['key' => 'total', 'label' => 'Invoice total', 'numeric' => true],
            ['key' => 'balance', 'label' => 'Outstanding', 'numeric' => true],
            ['key' => 'status', 'label' => 'Status'],
        ], $sales->map(fn (Sale $sale): array => [
            'reference' => $sale->invoice_number,
            'party' => $sale->customer?->name ?? $sale->customer_name_snapshot,
            'due_date' => $sale->due_date?->format('Y-m-d') ?? '—',
            'total' => (float) $sale->total,
            'balance' => (float) $sale->balance_due,
            'status' => $sale->due_date && $sale->due_date->toDateString() < today()->toDateString() ? 'Overdue' : 'Open',
        ])->all(), [
            'open_invoices' => $sales->count(),
            'outstanding' => (float) $sales->sum('balance_due'),
            'overdue' => (float) $sales->filter(fn (Sale $sale): bool => $sale->due_date && $sale->due_date->isPast())->sum('balance_due'),
        ]);
    }

    private function payablesReport(int $businessId, string $from, string $to): array
    {
        $purchases = Purchase::query()->with('supplier:id,name')
            ->where('business_id', $businessId)
            ->whereBetween('purchase_date', [$from, $to])
            ->where('balance_due', '>', 0)
            ->get()
            ->map(fn (Purchase $purchase): array => [
                'reference' => $purchase->purchase_number,
                'date' => $purchase->purchase_date->format('Y-m-d'),
                'party' => $purchase->supplier?->name ?? $purchase->supplier_name_snapshot,
                'due_date' => $purchase->due_date?->format('Y-m-d') ?? '—',
                'balance' => (float) $purchase->balance_due,
                'type' => 'Purchase',
            ]);
        $expenses = Expense::query()->with('supplier:id,name')
            ->where('business_id', $businessId)
            ->whereBetween('expense_date', [$from, $to])
            ->where('balance_due', '>', 0)
            ->get()
            ->map(fn (Expense $expense): array => [
                'reference' => $expense->expense_number,
                'date' => $expense->expense_date->format('Y-m-d'),
                'party' => $expense->supplier?->name ?? $expense->supplier_name_snapshot ?? '—',
                'due_date' => '—',
                'balance' => (float) $expense->balance_due,
                'type' => 'Expense',
            ]);
        $rows = $purchases->concat($expenses)->sortBy('due_date')->values();

        return $this->table('Payables', [
            ['key' => 'reference', 'label' => 'Reference'],
            ['key' => 'type', 'label' => 'Type'],
            ['key' => 'date', 'label' => 'Date'],
            ['key' => 'party', 'label' => 'Supplier'],
            ['key' => 'due_date', 'label' => 'Due date'],
            ['key' => 'balance', 'label' => 'Outstanding', 'numeric' => true],
        ], $rows->all(), [
            'open_items' => $rows->count(),
            'outstanding' => (float) $rows->sum('balance'),
        ]);
    }

    private function customerLedgerReport(int $businessId, string $from, string $to): array
    {
        $sales = Sale::query()->with('customer:id,name')
            ->where('business_id', $businessId)
            ->whereBetween('sale_date', [$from, $to])
            ->orderByDesc('sale_date')
            ->limit(1000)
            ->get();

        return $this->table('Customer ledgers', [
            ['key' => 'date', 'label' => 'Date'],
            ['key' => 'party', 'label' => 'Customer'],
            ['key' => 'reference', 'label' => 'Invoice'],
            ['key' => 'total', 'label' => 'Invoice', 'numeric' => true],
            ['key' => 'paid', 'label' => 'Paid', 'numeric' => true],
            ['key' => 'balance', 'label' => 'Balance', 'numeric' => true],
        ], $sales->map(fn (Sale $sale): array => [
            'date' => $sale->sale_date->format('Y-m-d'),
            'party' => $sale->customer?->name ?? $sale->customer_name_snapshot,
            'reference' => $sale->invoice_number,
            'total' => (float) $sale->total,
            'paid' => (float) $sale->amount_paid,
            'balance' => (float) $sale->balance_due,
        ])->all(), [
            'customers' => $sales->pluck('customer_name_snapshot')->unique()->count(),
            'invoiced' => (float) $sales->sum('total'),
            'collected' => (float) $sales->sum('amount_paid'),
            'outstanding' => (float) $sales->sum('balance_due'),
        ]);
    }

    private function vendorLedgerReport(int $businessId, string $from, string $to): array
    {
        $purchases = Purchase::query()->with('supplier:id,name')
            ->where('business_id', $businessId)
            ->whereBetween('purchase_date', [$from, $to])
            ->get()
            ->map(fn (Purchase $purchase): array => [
                'date' => $purchase->purchase_date->format('Y-m-d'),
                'party' => $purchase->supplier?->name ?? $purchase->supplier_name_snapshot,
                'reference' => $purchase->purchase_number,
                'type' => 'Purchase',
                'total' => (float) $purchase->total,
                'paid' => (float) $purchase->amount_paid,
                'balance' => (float) $purchase->balance_due,
            ]);
        $expenses = Expense::query()->with('supplier:id,name')
            ->where('business_id', $businessId)
            ->whereBetween('expense_date', [$from, $to])
            ->get()
            ->map(fn (Expense $expense): array => [
                'date' => $expense->expense_date->format('Y-m-d'),
                'party' => $expense->supplier?->name ?? $expense->supplier_name_snapshot ?? '—',
                'reference' => $expense->expense_number,
                'type' => 'Expense',
                'total' => (float) $expense->total,
                'paid' => (float) $expense->amount_paid,
                'balance' => (float) $expense->balance_due,
            ]);
        $rows = $purchases->concat($expenses)->sortByDesc('date')->values();

        return $this->table('Vendor ledgers', [
            ['key' => 'date', 'label' => 'Date'],
            ['key' => 'party', 'label' => 'Vendor'],
            ['key' => 'reference', 'label' => 'Reference'],
            ['key' => 'type', 'label' => 'Type'],
            ['key' => 'total', 'label' => 'Billed', 'numeric' => true],
            ['key' => 'paid', 'label' => 'Paid', 'numeric' => true],
            ['key' => 'balance', 'label' => 'Balance', 'numeric' => true],
        ], $rows->all(), [
            'vendors' => $rows->pluck('party')->unique()->count(),
            'billed' => (float) $rows->sum('total'),
            'paid' => (float) $rows->sum('paid'),
            'outstanding' => (float) $rows->sum('balance'),
        ]);
    }

    private function inventoryReport(int $businessId): array
    {
        $products = Product::query()->with('category:id,name')
            ->where('business_id', $businessId)
            ->where('track_stock', true)
            ->orderBy('name')
            ->limit(2000)
            ->get();
        $rows = $products->map(fn (Product $product): array => [
            'product' => $product->name,
            'sku' => $product->sku ?? '—',
            'category' => $product->category?->name ?? '—',
            'quantity' => (float) $product->current_stock,
            'unit_cost' => (float) $product->purchase_price,
            'stock_value' => (float) $product->current_stock * (float) $product->purchase_price,
            'threshold' => $product->low_stock_threshold === null ? '—' : (float) $product->low_stock_threshold,
        ])->all();

        return $this->table('Inventory', [
            ['key' => 'product', 'label' => 'Product'],
            ['key' => 'sku', 'label' => 'SKU'],
            ['key' => 'category', 'label' => 'Category'],
            ['key' => 'quantity', 'label' => 'On hand', 'numeric' => true],
            ['key' => 'unit_cost', 'label' => 'Unit cost', 'numeric' => true],
            ['key' => 'stock_value', 'label' => 'Stock value', 'numeric' => true],
            ['key' => 'threshold', 'label' => 'Low-stock level', 'numeric' => true],
        ], $rows, [
            'products' => $products->count(),
            'units_on_hand' => (float) $products->sum('current_stock'),
            'stock_value' => array_sum(array_column($rows, 'stock_value')),
            'low_stock' => $products->filter(fn (Product $product): bool => $product->low_stock_threshold !== null && (float) $product->current_stock <= (float) $product->low_stock_threshold)->count(),
        ], ['Stock value is estimated from current quantity multiplied by the product purchase price.']);
    }

    private function profitByProductReport(int $businessId, string $from, string $to): array
    {
        $items = SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->leftJoin('products', 'products.id', '=', 'sale_items.product_id')
            ->where('sales.business_id', $businessId)
            ->whereNull('sales.deleted_at')
            ->whereBetween('sales.sale_date', [$from, $to])
            ->selectRaw('COALESCE(products.name, sale_items.description) as product, SUM(sale_items.quantity) as quantity, SUM(sale_items.taxable_amount) as sales, SUM(sale_items.inventory_cost) as cost')
            ->groupBy('sale_items.product_id', 'sale_items.description', 'products.name')
            ->orderByDesc('sales')
            ->limit(1000)
            ->get();
        $rows = $items->map(fn ($item): array => [
            'product' => $item->product,
            'quantity' => (float) $item->quantity,
            'sales' => (float) $item->sales,
            'cost' => (float) $item->cost,
            'profit' => (float) $item->sales - (float) $item->cost,
        ])->all();

        return $this->table('Profit by product', [
            ['key' => 'product', 'label' => 'Product'],
            ['key' => 'quantity', 'label' => 'Quantity', 'numeric' => true],
            ['key' => 'sales', 'label' => 'Taxable sales', 'numeric' => true],
            ['key' => 'cost', 'label' => 'Recorded stock cost', 'numeric' => true],
            ['key' => 'profit', 'label' => 'Gross profit', 'numeric' => true],
        ], $rows, [
            'products' => count($rows),
            'sales' => array_sum(array_column($rows, 'sales')),
            'cost' => array_sum(array_column($rows, 'cost')),
            'gross_profit' => array_sum(array_column($rows, 'profit')),
        ], ['Profit uses stored inventory cost; services or untracked products may have no cost recorded.']);
    }

    private function trialBalanceReport(int $businessId, string $to): array
    {
        $accounts = collect(ChartAccountCatalog::forBusiness($businessId))->keyBy('account_code');
        $balances = $this->accountBalances($businessId, null, $to);
        $rows = [];
        foreach ($balances as $code => $balance) {
            $net = $balance['debit'] - $balance['credit'];
            if (abs($net) < 0.005) {
                continue;
            }
            $account = $accounts->get($code);
            $rows[] = [
                'code' => $account['display_code'] ?? $code,
                'account' => $account['name'] ?? $code,
                'type' => $account['account_type'] ?? 'other',
                'debit' => max(0, $net),
                'credit' => max(0, -$net),
            ];
        }

        return $this->table('Trial balance', [
            ['key' => 'code', 'label' => 'Code'],
            ['key' => 'account', 'label' => 'Account'],
            ['key' => 'type', 'label' => 'Type'],
            ['key' => 'debit', 'label' => 'Debit', 'numeric' => true],
            ['key' => 'credit', 'label' => 'Credit', 'numeric' => true],
        ], $rows, [
            'accounts' => count($rows),
            'debits' => array_sum(array_column($rows, 'debit')),
            'credits' => array_sum(array_column($rows, 'credit')),
        ], ['Balances include all posted journal entries through the report end date.']);
    }

    private function profitAndLossReport(int $businessId, string $from, string $to, string $report): array
    {
        $title = $report === 'income-tax-compliance' ? 'Income & compliance summary' : 'Profit & Loss';
        $accounts = collect(ChartAccountCatalog::forBusiness($businessId))->keyBy('account_code');
        $balances = $this->accountBalances($businessId, $from, $to);
        $rows = [];
        $income = 0.0;
        $expenses = 0.0;
        foreach ($balances as $code => $balance) {
            $account = $accounts->get($code);
            if (! $account || ! in_array($account['account_type'], ['income', 'expenses'], true)) {
                continue;
            }
            $amount = $account['account_type'] === 'income'
                ? $balance['credit'] - $balance['debit']
                : $balance['debit'] - $balance['credit'];
            if (abs($amount) < 0.005) {
                continue;
            }
            if ($account['account_type'] === 'income') {
                $income += $amount;
            } else {
                $expenses += $amount;
            }
            $rows[] = [
                'section' => $account['account_type'] === 'income' ? 'Income' : 'Expenses',
                'account' => $account['name'],
                'amount' => $amount,
            ];
        }

        return $this->table($title, [
            ['key' => 'section', 'label' => 'Section'],
            ['key' => 'account', 'label' => 'Account'],
            ['key' => 'amount', 'label' => 'Amount', 'numeric' => true],
        ], $rows, [
            'income' => $income,
            'expenses' => $expenses,
            'net_profit' => $income - $expenses,
        ], $report === 'income-tax-compliance'
            ? ['This is a bookkeeping summary, not tax advice or a filed income-tax return. Confirm compliance with your tax professional.']
            : []);
    }

    private function balanceSheetReport(int $businessId, string $to): array
    {
        $accounts = collect(ChartAccountCatalog::forBusiness($businessId))->keyBy('account_code');
        $balances = $this->accountBalances($businessId, null, $to);
        $rows = [];
        $assets = 0.0;
        $liabilities = 0.0;
        $equity = 0.0;
        $currentEarnings = 0.0;
        foreach ($balances as $code => $balance) {
            $account = $accounts->get($code);
            if (! $account) {
                continue;
            }
            $type = $account['account_type'];
            $amount = in_array($type, ['assets', 'expenses'], true)
                ? $balance['debit'] - $balance['credit']
                : $balance['credit'] - $balance['debit'];
            if ($type === 'income') {
                $currentEarnings += $amount;
                continue;
            }
            if ($type === 'expenses') {
                $currentEarnings -= $amount;
                continue;
            }
            if (abs($amount) < 0.005) {
                continue;
            }
            if ($type === 'assets') {
                $assets += $amount;
                $section = 'Assets';
            } elseif ($type === 'liabilities') {
                $liabilities += $amount;
                $section = 'Liabilities';
            } elseif ($type === 'equity') {
                $equity += $amount;
                $section = 'Equity';
            } else {
                continue;
            }
            $rows[] = ['section' => $section, 'account' => $account['name'], 'amount' => $amount];
        }
        if (abs($currentEarnings) >= 0.005) {
            $equity += $currentEarnings;
            $rows[] = ['section' => 'Equity', 'account' => 'Current earnings', 'amount' => $currentEarnings];
        }

        return $this->table('Balance sheet', [
            ['key' => 'section', 'label' => 'Section'],
            ['key' => 'account', 'label' => 'Account'],
            ['key' => 'amount', 'label' => 'Balance', 'numeric' => true],
        ], $rows, [
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'liabilities_and_equity' => $liabilities + $equity,
            'difference' => $assets - $liabilities - $equity,
        ], ['Income and expense balances through the report date are included as current earnings.']);
    }

    private function dayBookReport(int $businessId, string $from, string $to): array
    {
        $entries = JournalEntry::query()->with('lines')
            ->where('business_id', $businessId)
            ->whereBetween('entry_date', [$from, $to])
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->limit(1000)
            ->get();
        $rows = $entries->map(fn (JournalEntry $entry): array => [
            'date' => $entry->entry_date->format('Y-m-d'),
            'reference' => strtoupper(str_replace('_', ' ', $entry->source_type)).' #'.$entry->source_id,
            'description' => $entry->description,
            'source' => str($entry->source_type)->replace('_', ' ')->title()->toString(),
            'debit' => (float) $entry->lines->sum('debit'),
            'credit' => (float) $entry->lines->sum('credit'),
        ])->all();

        return $this->table('Day book & journal', [
            ['key' => 'date', 'label' => 'Date'],
            ['key' => 'reference', 'label' => 'Reference'],
            ['key' => 'description', 'label' => 'Description'],
            ['key' => 'source', 'label' => 'Source'],
            ['key' => 'debit', 'label' => 'Debit', 'numeric' => true],
            ['key' => 'credit', 'label' => 'Credit', 'numeric' => true],
        ], $rows, [
            'entries' => count($rows),
            'debits' => array_sum(array_column($rows, 'debit')),
            'credits' => array_sum(array_column($rows, 'credit')),
        ]);
    }

    private function accountLedgerReport(int $businessId, string $from, string $to, ?string $accountCode): array
    {
        $accounts = ChartAccountCatalog::forBusiness($businessId);
        $accountOptions = array_map(static fn (array $account): array => [
            'code' => $account['account_code'],
            'name' => $account['name'],
        ], $accounts);
        if (! $accountCode) {
            $report = $this->table('Account ledgers', [
                ['key' => 'code', 'label' => 'Code'],
                ['key' => 'name', 'label' => 'Account'],
                ['key' => 'account_type', 'label' => 'Type'],
            ], array_map(static fn (array $account): array => [
                'code' => $account['account_code'],
                'name' => $account['name'],
                'account_type' => $account['account_type'],
            ], $accounts), ['accounts' => count($accounts)], [], ['Select an account to see its dated ledger activity.']);
            $report['account_options'] = $accountOptions;

            return $report;
        }
        $account = collect($accounts)->firstWhere('account_code', $accountCode);
        if (! $account) {
            abort(404, 'The selected account does not exist in this business.');
        }

        $opening = (float) DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.business_id', $businessId)
            ->where('journal_lines.account_code', $accountCode)
            ->whereDate('journal_entries.entry_date', '<', $from)
            ->selectRaw('COALESCE(SUM(journal_lines.debit - journal_lines.credit), 0) as balance')
            ->value('balance');
        $lines = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.business_id', $businessId)
            ->where('journal_lines.account_code', $accountCode)
            ->whereBetween('journal_entries.entry_date', [$from, $to])
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_entries.id')
            ->select([
                'journal_entries.entry_date as date',
                'journal_entries.description',
                'journal_lines.debit',
                'journal_lines.credit',
            ])
            ->limit(2000)
            ->get();
        $running = $opening;
        $rows = $lines->map(function ($line) use (&$running): array {
            $running += (float) $line->debit - (float) $line->credit;

            return [
                'date' => $line->date,
                'description' => $line->description,
                'debit' => (float) $line->debit,
                'credit' => (float) $line->credit,
                'balance' => $running,
            ];
        })->all();

        $report = $this->table('Account ledger: '.$account['name'], [
            ['key' => 'date', 'label' => 'Date'],
            ['key' => 'description', 'label' => 'Description'],
            ['key' => 'debit', 'label' => 'Debit', 'numeric' => true],
            ['key' => 'credit', 'label' => 'Credit', 'numeric' => true],
            ['key' => 'balance', 'label' => 'Running balance', 'numeric' => true],
        ], $rows, [
            'opening_balance' => $opening,
            'closing_balance' => $running,
            'debits' => (float) $lines->sum('debit'),
            'credits' => (float) $lines->sum('credit'),
        ], ['Account: '.$account['display_code'].' · '.$account['name']]);
        $report['account_options'] = $accountOptions;

        return $report;
    }

    private function cashFlowReport(int $businessId, string $from, string $to): array
    {
        $cashAccounts = ['cash', 'bank', 'upi_clearing', 'card_clearing'];
        $entries = DB::table('journal_entries')
            ->join('journal_lines', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entries.business_id', $businessId)
            ->whereIn('journal_lines.account_code', $cashAccounts)
            ->whereBetween('journal_entries.entry_date', [$from, $to])
            ->groupBy('journal_entries.id', 'journal_entries.entry_date', 'journal_entries.description', 'journal_entries.source_type')
            ->orderByDesc('journal_entries.entry_date')
            ->selectRaw('journal_entries.entry_date as date, journal_entries.description, journal_entries.source_type as source, SUM(journal_lines.debit - journal_lines.credit) as net')
            ->limit(1000)
            ->get();
        $rows = $entries->map(fn ($entry): array => [
            'date' => $entry->date,
            'description' => $entry->description,
            'source' => str($entry->source)->replace('_', ' ')->title()->toString(),
            'inflow' => max(0, (float) $entry->net),
            'outflow' => max(0, -((float) $entry->net)),
            'net' => (float) $entry->net,
        ])->all();
        $opening = (float) DB::table('journal_entries')
            ->join('journal_lines', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entries.business_id', $businessId)
            ->whereIn('journal_lines.account_code', $cashAccounts)
            ->whereDate('journal_entries.entry_date', '<', $from)
            ->selectRaw('COALESCE(SUM(journal_lines.debit - journal_lines.credit), 0) as balance')
            ->value('balance');
        $movement = (float) DB::table('journal_entries')
            ->join('journal_lines', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entries.business_id', $businessId)
            ->whereIn('journal_lines.account_code', $cashAccounts)
            ->whereBetween('journal_entries.entry_date', [$from, $to])
            ->selectRaw('COALESCE(SUM(journal_lines.debit - journal_lines.credit), 0) as movement')
            ->value('movement');

        return $this->table('Cash flow', [
            ['key' => 'date', 'label' => 'Date'],
            ['key' => 'description', 'label' => 'Description'],
            ['key' => 'source', 'label' => 'Source'],
            ['key' => 'inflow', 'label' => 'Net inflow', 'numeric' => true],
            ['key' => 'outflow', 'label' => 'Net outflow', 'numeric' => true],
            ['key' => 'net', 'label' => 'Net movement', 'numeric' => true],
        ], $rows, [
            'opening_cash_and_bank' => $opening,
            'net_movement' => $movement,
            'closing_cash_and_bank' => $opening + $movement,
        ], ['Transfers between cash and bank accounts are netted within each journal entry.']);
    }

    private function cashForecastReport(int $businessId, string $from, string $to): array
    {
        $opening = (float) DB::table('journal_entries')
            ->join('journal_lines', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entries.business_id', $businessId)
            ->whereIn('journal_lines.account_code', ['cash', 'bank', 'upi_clearing', 'card_clearing'])
            ->whereDate('journal_entries.entry_date', '<', $from)
            ->selectRaw('COALESCE(SUM(journal_lines.debit - journal_lines.credit), 0) as balance')
            ->value('balance');
        $receivables = Sale::query()->where('business_id', $businessId)->where('balance_due', '>', 0)
            ->whereBetween('due_date', [$from, $to])->get();
        $purchasePayables = Purchase::query()->where('business_id', $businessId)->where('balance_due', '>', 0)
            ->whereBetween('due_date', [$from, $to])->get();
        $rows = [];
        foreach ($receivables as $sale) {
            $rows[] = ['date' => $sale->due_date->format('Y-m-d'), 'reference' => $sale->invoice_number, 'party' => $sale->customer_name_snapshot, 'type' => 'Expected receipt', 'amount' => (float) $sale->balance_due];
        }
        foreach ($purchasePayables as $purchase) {
            $rows[] = ['date' => $purchase->due_date->format('Y-m-d'), 'reference' => $purchase->purchase_number, 'party' => $purchase->supplier_name_snapshot, 'type' => 'Expected payment', 'amount' => -((float) $purchase->balance_due)];
        }
        usort($rows, static fn (array $left, array $right): int => strcmp($left['date'], $right['date']));
        $inflows = (float) $receivables->sum('balance_due');
        $outflows = (float) $purchasePayables->sum('balance_due');

        return $this->table('Cash forecast', [
            ['key' => 'date', 'label' => 'Expected date'],
            ['key' => 'reference', 'label' => 'Reference'],
            ['key' => 'party', 'label' => 'Customer / supplier'],
            ['key' => 'type', 'label' => 'Type'],
            ['key' => 'amount', 'label' => 'Expected amount', 'numeric' => true],
        ], $rows, [
            'opening_cash_and_bank' => $opening,
            'expected_receipts' => $inflows,
            'expected_payments' => $outflows,
            'estimated_closing' => $opening + $inflows - $outflows,
        ], ['Forecast includes unpaid invoices and supplier bills with due dates in the selected range. Expenses without due dates are not projected; this is not a guarantee of collection or payment.']);
    }

    private function businessCheckupReport(int $businessId): array
    {
        $overdue = Sale::query()->where('business_id', $businessId)->where('balance_due', '>', 0)
            ->whereNotNull('due_date')->whereDate('due_date', '<', today())->get();
        $lowStock = Product::query()->where('business_id', $businessId)->where('track_stock', true)
            ->whereNotNull('low_stock_threshold')->whereColumn('current_stock', '<=', 'low_stock_threshold')->get();
        $expiredBatches = InventoryBatch::query()->where('business_id', $businessId)->where('quantity_remaining', '>', 0)
            ->whereNotNull('expiry_date')->whereDate('expiry_date', '<', today())->get();
        $openPayables = Purchase::query()->where('business_id', $businessId)->where('balance_due', '>', 0)->sum('balance_due')
            + Expense::query()->where('business_id', $businessId)->where('balance_due', '>', 0)->sum('balance_due');
        $rows = [
            ['check' => 'Overdue customer invoices', 'count' => $overdue->count(), 'amount' => (float) $overdue->sum('balance_due'), 'status' => $overdue->isEmpty() ? 'Clear' : 'Review'],
            ['check' => 'Low-stock products', 'count' => $lowStock->count(), 'amount' => null, 'status' => $lowStock->isEmpty() ? 'Clear' : 'Review'],
            ['check' => 'Expired batches with stock', 'count' => $expiredBatches->count(), 'amount' => null, 'status' => $expiredBatches->isEmpty() ? 'Clear' : 'Review'],
            ['check' => 'Outstanding supplier/expense payments', 'count' => null, 'amount' => (float) $openPayables, 'status' => $openPayables > 0 ? 'Review' : 'Clear'],
        ];

        return $this->table('Business check-up', [
            ['key' => 'check', 'label' => 'Check'],
            ['key' => 'count', 'label' => 'Items', 'numeric' => true],
            ['key' => 'amount', 'label' => 'Amount', 'numeric' => true],
            ['key' => 'status', 'label' => 'Status'],
        ], $rows, [
            'items_to_review' => count(array_filter($rows, static fn (array $row): bool => $row['status'] === 'Review')),
        ], ['Review these operational indicators regularly; this check-up is not a substitute for accounting or tax advice.']);
    }

    private function accountBalances(int $businessId, ?string $from, string $to): array
    {
        $query = DB::table('journal_entries')
            ->join('journal_lines', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entries.business_id', $businessId)
            ->whereDate('journal_entries.entry_date', '<=', $to);
        if ($from !== null) {
            $query->whereDate('journal_entries.entry_date', '>=', $from);
        }
        $balances = $query->groupBy('journal_lines.account_code')
            ->selectRaw('journal_lines.account_code, SUM(journal_lines.debit) as debit, SUM(journal_lines.credit) as credit')
            ->get();

        return $balances->mapWithKeys(static fn ($balance): array => [
            $balance->account_code => ['debit' => (float) $balance->debit, 'credit' => (float) $balance->credit],
        ])->all();
    }

    private function table(string $title, array $columns, array $rows, array $summary, array $notes = []): array
    {
        return compact('title', 'columns', 'rows', 'summary', 'notes');
    }
}
