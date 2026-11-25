<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ActivityController;
use App\Http\Controllers\Api\BankingController;
use App\Http\Controllers\Api\BusinessController;
use App\Http\Controllers\Api\CashTallyController;
use App\Http\Controllers\Api\ChartAccountsController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DataImportController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\GstReportController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\JournalController;
use App\Http\Controllers\Api\MonthEndCloseController;
use App\Http\Controllers\Api\PurchaseController;
use App\Http\Controllers\Api\ReturnNoteController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\ScanBillController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Middleware\EnsureActiveBusiness;
use Illuminate\Support\Facades\Route;

// Public routes
Route::get('/health', fn () => response()->json(['status' => 'ok']));
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Protected routes (Sanctum)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/businesses', [BusinessController::class, 'index']);
    Route::post('/businesses', [BusinessController::class, 'store']);
    Route::patch('/businesses/active', [BusinessController::class, 'activate']);

    Route::middleware(EnsureActiveBusiness::class)->group(function () {
        Route::get('/dashboard/summary', [DashboardController::class, 'summary']);
        Route::apiResource('customers', CustomerController::class);
        Route::apiResource('sales', SaleController::class);
        Route::apiResource('suppliers', SupplierController::class);
        Route::apiResource('purchases', PurchaseController::class);
        Route::apiResource('expenses', ExpenseController::class);
        Route::get('/inventory/products', [InventoryController::class, 'index']);
        Route::post('/inventory/products', [InventoryController::class, 'store']);
        Route::patch('/inventory/products/{product}', [InventoryController::class, 'update']);
        Route::delete('/inventory/products/{product}', [InventoryController::class, 'destroy']);
        Route::patch('/inventory/products/{product}/stock', [InventoryController::class, 'adjustStock']);
        Route::get('/inventory/categories', [InventoryController::class, 'categories']);
        Route::post('/inventory/categories', [InventoryController::class, 'storeCategory']);
        Route::delete('/inventory/categories/{category}', [InventoryController::class, 'destroyCategory']);
        Route::get('/inventory/reorder', [InventoryController::class, 'reorder']);
        Route::get('/inventory/expiring', [InventoryController::class, 'expiring']);
        Route::get('/banking/accounts', [BankingController::class, 'index']);
        Route::post('/banking/accounts', [BankingController::class, 'store']);
        Route::delete('/banking/accounts/{account}', [BankingController::class, 'destroy']);
        Route::post('/banking/accounts/{account}/statements', [BankingController::class, 'import']);
        Route::get('/banking/accounts/{account}/reconciliation', [BankingController::class, 'reconciliation']);
        Route::post('/banking/accounts/{account}/reconciliation', [BankingController::class, 'match']);
        Route::patch('/banking/accounts/{account}/legacy-lines/{journalLine}', [BankingController::class, 'assignLegacy']);
        Route::delete('/banking/accounts/{account}/reconciliation/{transaction}', [BankingController::class, 'unmatch']);
        Route::get('/invoices', [InvoiceController::class, 'index']);
        Route::get('/invoices/{invoice}', [InvoiceController::class, 'show']);
        Route::get('/gst/report', [GstReportController::class, 'report']);
        Route::get('/gst/export', [GstReportController::class, 'export']);
        Route::get('/cash-tally', [CashTallyController::class, 'index']);
        Route::post('/cash-tally', [CashTallyController::class, 'store']);
        Route::get('/activity', [ActivityController::class, 'index']);
        Route::get('/month-end-close', [MonthEndCloseController::class, 'show']);
        Route::get('/journal-entries', [JournalController::class, 'index']);
        Route::post('/journal-entries', [JournalController::class, 'store']);
        Route::get('/chart-of-accounts', [ChartAccountsController::class, 'index']);
        Route::post('/chart-of-accounts', [ChartAccountsController::class, 'store']);
        Route::get('/imports', [DataImportController::class, 'index']);
        Route::post('/imports/{type}/preview', [DataImportController::class, 'preview']);
        Route::post('/imports/{dataImport}/commit', [DataImportController::class, 'commit']);
        Route::get('/credit-notes', [ReturnNoteController::class, 'creditNotes']);
        Route::post('/credit-notes', [ReturnNoteController::class, 'storeCredit']);
        Route::get('/debit-notes', [ReturnNoteController::class, 'debitNotes']);
        Route::post('/debit-notes', [ReturnNoteController::class, 'storeDebit']);
        Route::get('/scan-bills', [ScanBillController::class, 'index']);
        Route::post('/scan-bills', [ScanBillController::class, 'store']);
        Route::get('/scan-bills/{scanBill}', [ScanBillController::class, 'show']);
        Route::get('/scan-bills/{scanBill}/file', [ScanBillController::class, 'file']);
        Route::delete('/scan-bills/{scanBill}', [ScanBillController::class, 'destroy']);
    });
});
