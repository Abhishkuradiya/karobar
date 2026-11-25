<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BusinessController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\SaleController;
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
    });
});
