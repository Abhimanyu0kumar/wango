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
    Route::middleware([
        'admin.auth',
        'json.api',
        'account.active:admin',
        'role:super-admin|admin,admin',
        'throttle:20,1',
    ])->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});
