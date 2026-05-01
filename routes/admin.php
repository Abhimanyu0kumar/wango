<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use App\Http\Controllers\Admin\{
    AdminController,
    AdminEngineController,
    AuthController,
    DashboardController,
    RoleController,
    KycController,
    WithdrawalController,
    WalletController,
    UserController,
    CategoryController,
    GameController,
    GameRoundController,
    LuckyDrawRoundController,
    TeenPattiRoundController,
    PokerRoundController,
    ReportsController,
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
        // 'throttle:20,1',
    ])->group(function () {

        Route::post('/logout', [AuthController::class, 'logout']);

        // Current Admin Profile Routes
        Route::get('/me', [AdminController::class, 'me']);
        Route::put('/me', [AdminController::class, 'updateMe']);

        // Admin Management Routes
        Route::get('/admins', [AdminController::class, 'index']);
        Route::post('/admins', [AdminController::class, 'store']);
        Route::get('/admins/{admin}', [AdminController::class, 'show']);
        Route::put('/admins/{admin}', [AdminController::class, 'update']);
        Route::delete('/admins/{admin}', [AdminController::class, 'destroy']);
        Route::patch('/admins/{admin}/toggle-status', [AdminController::class, 'toggleStatus']);
        Route::get('/roles', [AdminController::class, 'listRoles']);

        // User API Routes
        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::get('/users/{user}', [UserController::class, 'show']);
        Route::put('/users/{user}', [UserController::class, 'update']);
        Route::delete('/users/{user}', [UserController::class, 'destroy']);
        Route::patch('/users/{user}/user-active', [UserController::class, 'UserActive']);
        Route::patch('/users/{user}/user-blocked', [UserController::class, 'UserBlocked']);

        // User Profile API Routes
        Route::get('/users/{user}/profile', [UserController::class, 'showProfile']);
        Route::post('/users/{user}/profile', [UserController::class, 'updateProfile']);
        Route::put('/users/{user}/profile', [UserController::class, 'updateProfile']);
        Route::delete('/users/{user}/profile', [UserController::class, 'deleteProfile']);

        // User Wallet API Routes
        Route::get('/users/{user}/wallets', [UserController::class, 'wallets']);
        Route::get('/users/{user}/withdrawals', [UserController::class, 'withdrawals']);

        // Category API Routes  
        Route::get('/categories', [CategoryController::class, 'index']);
        Route::post('/categories', [CategoryController::class, 'store']);
        Route::get('/categories/{category}', [CategoryController::class, 'show']);
        Route::put('/categories/{category}', [CategoryController::class, 'update']);
        Route::delete('/categories/{category}', [CategoryController::class, 'destroy']);
        Route::patch('/categories/{category}/toggle-status', [CategoryController::class, 'toggleStatus']);

        // Game API Routes
        Route::get('/games', [GameController::class, 'index']);
        Route::post('/games', [GameController::class, 'store']);
        Route::get('/games/{game}', [GameController::class, 'show']);
        Route::put('/games/{game}', [GameController::class, 'update']);
        Route::delete('/games/{game}', [GameController::class, 'destroy']);
        Route::patch('/games/{game}/toggle-status', [GameController::class, 'toggleStatus']);
        Route::patch('/games/{game}/maintenance', [GameController::class, 'setMaintenance']);

        // Game Engine Management Routes
        Route::get('/engines', [AdminEngineController::class, 'index']);
        Route::get('/engines/{gameId}', [AdminEngineController::class, 'show']);
        Route::post('/engines/{gameId}/start', [AdminEngineController::class, 'start']);
        Route::post('/engines/{gameId}/stop', [AdminEngineController::class, 'stop']);
        Route::post('/engines/{gameId}/pause', [AdminEngineController::class, 'pause']);
        Route::post('/engines/{gameId}/resume', [AdminEngineController::class, 'resume']);
        Route::post('/engines/{gameId}/force-stop', [AdminEngineController::class, 'forceStop']);
        Route::post('/engines/{gameId}/restart', [AdminEngineController::class, 'restart']);

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

        // Teen Patti Round API Routes
        Route::get('/teen-patti-rounds', [TeenPattiRoundController::class, 'index']);
        Route::post('/teen-patti-rounds', [TeenPattiRoundController::class, 'store']);
        Route::get('/teen-patti-rounds/{id}', [TeenPattiRoundController::class, 'show']);
        Route::put('/teen-patti-rounds/{id}', [TeenPattiRoundController::class, 'update']);
        Route::delete('/teen-patti-rounds/{id}', [TeenPattiRoundController::class, 'destroy']);
        Route::patch('/teen-patti-rounds/{id}/status', [TeenPattiRoundController::class, 'updateStatus']);
        Route::patch('/teen-patti-rounds/{id}/result', [TeenPattiRoundController::class, 'setResult']);
        Route::patch('/teen-patti-rounds/{id}/cancel', [TeenPattiRoundController::class, 'cancel']);

        // Poker Round API Routes
        Route::get('/poker-rounds', [PokerRoundController::class, 'index']);
        Route::post('/poker-rounds', [PokerRoundController::class, 'store']);
        Route::get('/poker-rounds/{id}', [PokerRoundController::class, 'show']);
        Route::put('/poker-rounds/{id}', [PokerRoundController::class, 'update']);
        Route::delete('/poker-rounds/{id}', [PokerRoundController::class, 'destroy']);
        Route::patch('/poker-rounds/{id}/flop', [PokerRoundController::class, 'dealFlop']);
        Route::patch('/poker-rounds/{id}/turn', [PokerRoundController::class, 'dealTurn']);
        Route::patch('/poker-rounds/{id}/river', [PokerRoundController::class, 'dealRiver']);
        Route::patch('/poker-rounds/{id}/showdown', [PokerRoundController::class, 'evaluateShowdown']);
        Route::patch('/poker-rounds/{id}/settle', [PokerRoundController::class, 'settle']);
        Route::patch('/poker-rounds/{id}/cancel', [PokerRoundController::class, 'cancel']);

        // Wallet API Routes
        Route::get('/wallets', [WalletController::class, 'index']);
        Route::post('/wallets', [WalletController::class, 'store']);
        Route::get('/wallets/{id}', [WalletController::class, 'show']);
        Route::get('/wallets/{id}/ledgers', [WalletController::class, 'ledgers']);
        Route::post('/wallets/credit', [WalletController::class, 'credit']);
        Route::post('/wallets/{id}/credit', [WalletController::class, 'credit']);
        Route::post('/wallets/{id}/debit', [WalletController::class, 'debit']);
        Route::post('/wallets/{id}/lock', [WalletController::class, 'lock']);
        Route::post('/wallets/{id}/unlock', [WalletController::class, 'unlock']);
        Route::post('/wallets/{id}/freeze', [WalletController::class, 'freeze']);
        Route::post('/wallets/{id}/unfreeze', [WalletController::class, 'unfreeze']);
        Route::patch('/wallets/{id}/status', [WalletController::class, 'updateStatus']);

        // Withdrawal API Routes
        Route::get('/withdrawals', [WithdrawalController::class, 'index']);
        Route::get('/withdrawals/stats', [WithdrawalController::class, 'stats']);
        Route::get('/withdrawals/{id}', [WithdrawalController::class, 'show']);
        Route::post('/withdrawals/{id}/approve', [WithdrawalController::class, 'approve']);
        Route::post('/withdrawals/{id}/reject', [WithdrawalController::class, 'reject']);
        Route::post('/withdrawals/{id}/paid', [WithdrawalController::class, 'markAsPaid']);
        Route::post('/withdrawals/{id}/cancel', [WithdrawalController::class, 'cancel']);

        // Dashboard API Routes
        Route::get('/dashboard/summary', [DashboardController::class, 'summary']);
        Route::get('/dashboard/revenue', [DashboardController::class, 'revenue']);
        Route::get('/dashboard/active-users', [DashboardController::class, 'activeUsers']);
        Route::get('/dashboard/game-stats', [DashboardController::class, 'gameStats']);
        Route::get('/dashboard/withdrawal-pending', [DashboardController::class, 'withdrawalPending']);

        // KYC Management Routes
        Route::get('/kyc', [KycController::class, 'index']);
        Route::get('/kyc/stats', [KycController::class, 'stats']);
        Route::get('/kyc/{id}', [KycController::class, 'show']);
        Route::post('/kyc/{id}/approve', [KycController::class, 'approve']);
        Route::post('/kyc/{id}/reject', [KycController::class, 'reject']);
        Route::post('/kyc/bulk-action', [KycController::class, 'bulkAction']);

        // Reports & Analytics Routes
        Route::get('/reports/user-activity', [ReportsController::class, 'userActivity']);
        Route::get('/reports/financial', [ReportsController::class, 'financial']);
        Route::get('/reports/game-performance', [ReportsController::class, 'gamePerformance']);
        Route::get('/reports/transactions', [ReportsController::class, 'transactions']);
        Route::get('/reports/audit-logs', [ReportsController::class, 'auditLogs']);
        Route::get('/reports/export', [ReportsController::class, 'export']);
    });
});
