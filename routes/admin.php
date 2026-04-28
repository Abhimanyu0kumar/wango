<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\{
    AuthController,
    DashboardController,
    RoleController,
    KycController,
    WithdrawalController,
};

Route::prefix('admin/v1')->group(function () {

    // Public route
    Route::post('/login', [AuthController::class, 'login']);

    // Protected routes
    Route::middleware(['auth:user', 'role:super-admin|admin'])->group(function () {

        Route::post('/logout', [AuthController::class, 'logout']);
    });
});
