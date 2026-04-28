<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\User\AuthController;

Route::prefix('user/v1')->group(function () {

    // Public routes
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/signup', [AuthController::class, 'signup']);

    // Protected routes
    Route::middleware(['auth:api', 'json.api', 'account.active:api', 'throttle:30,1'])->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});
