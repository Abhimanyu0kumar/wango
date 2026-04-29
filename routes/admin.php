<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use App\Http\Controllers\Admin\{
    AdminController,
    AuthController,
    DashboardController,
    RoleController,
    KycController,
    WithdrawalController,
    UserController,
    CategoryController,
    GameController,
    GameRoundController,
    LuckyDrawRoundController,
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

        // Current Admin Profile Routes
        Route::get('/me', [AdminController::class, 'me']);
        Route::put('/me', [AdminController::class, 'updateMe']);

        // Admin Management Routes
        Route::get('/admins', [AdminController::class, 'index']);
        Route::post('/admins', [AdminController::class, 'store']);
        Route::get('/admins/{id}', [AdminController::class, 'show']);
        Route::put('/admins/{id}', [AdminController::class, 'update']);
        Route::delete('/admins/{id}', [AdminController::class, 'destroy']);
        Route::patch('/admins/{id}/toggle-status', [AdminController::class, 'toggleStatus']);

        // User API Routes
        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::get('/users/{id}', [UserController::class, 'show']);
        Route::put('/users/{id}', [UserController::class, 'update']);
        Route::delete('/users/{id}', [UserController::class, 'destroy']);
        Route::patch('/users/{id}/user-active', [UserController::class, 'UserActive']);
        Route::patch('/users/{id}/user-blocked', [UserController::class, 'UserBlocked']);

        // Category API Routes
        Route::get('/categories', [CategoryController::class, 'index']);
        Route::post('/categories', [CategoryController::class, 'store']);
        Route::get('/categories/{id}', [CategoryController::class, 'show']);
        Route::put('/categories/{id}', [CategoryController::class, 'update']);
        Route::delete('/categories/{id}', [CategoryController::class, 'destroy']);
        Route::patch('/categories/{id}/toggle-status', [CategoryController::class, 'toggleStatus']);

        // Game API Routes
        Route::get('/games', [GameController::class, 'index']);
        Route::post('/games', [GameController::class, 'store']);
        Route::get('/games/{id}', [GameController::class, 'show']);
        Route::put('/games/{id}', [GameController::class, 'update']);
        Route::delete('/games/{id}', [GameController::class, 'destroy']);
        Route::patch('/games/{id}/toggle-status', [GameController::class, 'toggleStatus']);
        Route::patch('/games/{id}/maintenance', [GameController::class, 'setMaintenance']);

        // Game Round API Routes
        Route::get('/game-rounds', [GameRoundController::class, 'index']);
        Route::post('/game-rounds', [GameRoundController::class, 'store']);
        Route::get('/game-rounds/{id}', [GameRoundController::class, 'show']);
        Route::put('/game-rounds/{id}', [GameRoundController::class, 'update']);
        Route::delete('/game-rounds/{id}', [GameRoundController::class, 'destroy']);
        Route::patch('/game-rounds/{id}/state', [GameRoundController::class, 'updateState']);
        Route::patch('/game-rounds/{id}/result', [GameRoundController::class, 'setResult']);
        Route::patch('/game-rounds/{id}/cancel', [GameRoundController::class, 'cancel']);

        // Lucky Draw Round API Routes
        Route::get('/lucky-draw-rounds', [LuckyDrawRoundController::class, 'index']);
        Route::post('/lucky-draw-rounds', [LuckyDrawRoundController::class, 'store']);
        Route::get('/lucky-draw-rounds/{id}', [LuckyDrawRoundController::class, 'show']);
        Route::put('/lucky-draw-rounds/{id}', [LuckyDrawRoundController::class, 'update']);
        Route::delete('/lucky-draw-rounds/{id}', [LuckyDrawRoundController::class, 'destroy']);
        Route::patch('/lucky-draw-rounds/{id}/status', [LuckyDrawRoundController::class, 'updateStatus']);
        Route::patch('/lucky-draw-rounds/{id}/result', [LuckyDrawRoundController::class, 'setResult']);
        Route::patch('/lucky-draw-rounds/{id}/cancel', [LuckyDrawRoundController::class, 'cancel']);
    });
});
