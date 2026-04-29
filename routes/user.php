<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\User\{
    AuthController,
    ProfileController,
    WalletController,
    TransactionController,
    BetController,
    DepositController,
    WithdrawalController,
    KycController,
    SecurityController,
};

Route::prefix('user/v1')->group(function () {

    // ==========================================
    // Public routes (no authentication required)
    // ==========================================
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/signup', [AuthController::class, 'signup']);

    // ==========================================
    // Protected routes (user authentication required)
    // ==========================================
    Route::middleware(['auth:api', 'json.api', 'account.active:api', 'throttle:30,1'])->group(function () {

        // Auth
        Route::post('/logout', [AuthController::class, 'logout']);

        // Profile - Self-service only (no ID parameter, always uses auth user)
        Route::get('/me', [ProfileController::class, 'show']);              // Get own profile
        Route::put('/me', [ProfileController::class, 'update']);             // Update own profile
        Route::put('/me/avatar', [ProfileController::class, 'updateAvatar']);// Update avatar

        // Wallet - Own wallet only
        Route::get('/wallet', [WalletController::class, 'show']);             // Get wallet balance
        Route::get('/wallet/ledgers', [WalletController::class, 'ledgers']); // Get ledger entries

        // Transactions - Own transactions only
        Route::get('/transactions', [TransactionController::class, 'index']); // List transactions
        Route::get('/transactions/{id}', [TransactionController::class, 'show']); // View transaction detail

        // Bets - Own bet history only
        Route::get('/bets', [BetController::class, 'index']);                 // List bet history
        Route::get('/bets/{id}', [BetController::class, 'show']);           // View bet detail

        // Deposits - Create and view own deposits
        Route::get('/deposits', [DepositController::class, 'index']);       // List deposits
        Route::post('/deposits', [DepositController::class, 'store']);      // Create deposit
        Route::get('/deposits/{id}', [DepositController::class, 'show']);    // View deposit status

        // Withdrawals - Request and view own withdrawals
        Route::get('/withdrawals', [WithdrawalController::class, 'index']); // List withdrawals
        Route::post('/withdrawals', [WithdrawalController::class, 'store']); // Request withdrawal
        Route::get('/withdrawals/{id}', [WithdrawalController::class, 'show']);// View withdrawal status

        // KYC - Upload own KYC documents
        Route::get('/kyc', [KycController::class, 'show']);                 // Get KYC status
        Route::post('/kyc', [KycController::class, 'store']);                 // Submit KYC
        Route::put('/kyc', [KycController::class, 'update']);                 // Update KYC documents

        // Security - Manage own security settings
        Route::get('/security', [SecurityController::class, 'show']);      // Get security settings
        Route::put('/security/password', [SecurityController::class, 'updatePassword']);
        Route::put('/security/pin', [SecurityController::class, 'updatePin']);
        Route::put('/security/2fa', [SecurityController::class, 'toggle2FA']);
        Route::get('/security/devices', [SecurityController::class, 'devices']); // List devices
        Route::delete('/security/devices/{id}', [SecurityController::class, 'revokeDevice']);
    });
});
