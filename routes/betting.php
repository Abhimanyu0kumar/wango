<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Betting\BetController;

Route::prefix('bet/v1')->group(function () {
    Route::middleware(['json.api'])->group(function () {
        Route::get('/odds', [BetController::class, 'odds']);

        Route::middleware(['auth:api', 'account.active:api', 'throttle:50,1'])->group(function () {
            Route::post('/place', [BetController::class, 'place']);
            Route::post('/cancel', [BetController::class, 'cancel']);
            Route::get('/history', [BetController::class, 'history']);
        });
    });
});
