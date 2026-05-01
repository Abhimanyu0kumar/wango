<?php

namespace App\Services;

use App\Models\Deposit;
use App\Models\Withdrawal;
use App\Models\WalletAccount;
use App\Models\WalletLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentGatewayService
{
    protected string $gateway = 'mock'; // mock, razorpay, stripe, etc.

    /**
     * Initialize a deposit transaction
     */
    public function initiateDeposit(array $data): array
    {
        $deposit = Deposit::create([
            'user_id' => $data['user_id'],
            'wallet_id' => $data['wallet_id'],
            'amount' => $data['amount'],
            'payment_method' => $data['payment_method'] ?? 'upi',
            'gateway_name' => $this->gateway,
            'gateway_transaction_id' => null,
            'gateway_response' => null,
            'status' => 'pending',
            'metadata' => $data['metadata'] ?? [],
        ]);

        // For mock gateway, auto-approve
        if ($this->gateway === 'mock') {
            return $this->mockApproveDeposit($deposit);
        }

        // For real gateways, return payment intent/link
        return [
            'deposit_id' => $deposit->id,
            'amount' => $deposit->amount,
            'payment_link' => null, // Gateway-specific
            'status' => 'pending',
        ];
    }

    /**
     * Handle deposit callback from gateway
     */
    public function handleDepositCallback(array $callbackData): array
    {
        $gatewayTransactionId = $callbackData['gateway_transaction_id'] ?? null;
        
        $deposit = Deposit::where('gateway_transaction_id', $gatewayTransactionId)
            ->where('status', 'pending')
            ->first();

        if (!$deposit) {
            throw new \Exception('Deposit not found or already processed');
        }

        $isSuccess = $callbackData['status'] === 'success';
        
        if ($isSuccess) {
            return $this->approveDeposit($deposit, $callbackData);
        } else {
            return $this->rejectDeposit($deposit, $callbackData);
        }
    }

    /**
     * Approve a deposit and credit wallet
     */
    public function approveDeposit(Deposit $deposit, array $callbackData = []): array
    {
        return DB::transaction(function () use ($deposit, $callbackData) {
            $deposit->update([
                'status' => 'approved',
                'gateway_transaction_id' => $callbackData['gateway_transaction_id'] ?? $deposit->gateway_transaction_id,
                'gateway_response' => $callbackData,
                'metadata' => array_merge($deposit->metadata ?? [], ['approved_at' => now()->toIso8601String()]),
            ]);

            $wallet = WalletAccount::where('id', $deposit->wallet_id)
                ->where('user_id', $deposit->user_id)
                ->first();

            if (!$wallet) {
                throw new \Exception('Wallet not found');
            }

            $wallet->available_balance += $deposit->amount;
            $wallet->save();

            WalletLedger::create([
                'wallet_id' => $wallet->id,
                'user_id' => $deposit->user_id,
                'txn_type' => 'deposit',
                'direction' => 'credit',
                'amount' => $deposit->amount,
                'balance_before' => $wallet->available_balance - $deposit->amount,
                'balance_after' => $wallet->available_balance,
                'reference_type' => 'deposit',
                'reference_id' => $deposit->id,
                'description' => 'Deposit via ' . ($deposit->payment_method ?? 'gateway'),
                'metadata' => ['gateway' => $deposit->gateway_name],
            ]);

            return [
                'deposit_id' => $deposit->id,
                'status' => 'approved',
                'amount_credited' => $deposit->amount,
            ];
        });
    }

    /**
     * Initiate a withdrawal
     */
    public function initiateWithdrawal(array $data): array
    {
        $withdrawal = Withdrawal::create([
            'user_id' => $data['user_id'],
            'wallet_id' => $data['wallet_id'],
            'amount' => $data['amount'],
            'payout_method' => $data['payout_method'] ?? 'bank_transfer',
            'account_details' => $data['account_details'] ?? [],
            'status' => 'pending',
            'metadata' => $data['metadata'] ?? [],
        ]);

        return [
            'withdrawal_id' => $withdrawal->id,
            'amount' => $withdrawal->amount,
            'status' => 'pending',
        ];
    }

    /**
     * Process withdrawal (admin action or gateway callback)
     */
    public function processWithdrawal(Withdrawal $withdrawal, string $action, ?array $gatewayResponse = null): array
    {
        return DB::transaction(function () use ($withdrawal, $action, $gatewayResponse) {
            if ($action === 'approve') {
                $withdrawal->update([
                    'status' => 'approved',
                    'approved_by' => $gatewayResponse['admin_id'] ?? null,
                    'processed_at' => now(),
                    'gateway_response' => $gatewayResponse,
                ]);

                // In real scenario, trigger gateway payout here
                // For mock, mark as paid
                if ($this->gateway === 'mock') {
                    $withdrawal->update([
                        'status' => 'paid',
                        'paid_at' => now(),
                    ]);
                }

                return ['status' => $withdrawal->status, 'message' => 'Withdrawal approved'];
            }

            if ($action === 'reject') {
                $withdrawal->update([
                    'status' => 'rejected',
                    'processed_at' => now(),
                    'gateway_response' => $gatewayResponse,
                ]);

                // Refund wallet
                $wallet = WalletAccount::find($withdrawal->wallet_id);
                if ($wallet) {
                    $wallet->available_balance += $withdrawal->amount;
                    $wallet->save();

                    WalletLedger::create([
                        'wallet_id' => $wallet->id,
                        'user_id' => $withdrawal->user_id,
                        'txn_type' => 'withdrawal_refund',
                        'direction' => 'credit',
                        'amount' => $withdrawal->amount,
                        'balance_before' => $wallet->available_balance - $withdrawal->amount,
                        'balance_after' => $wallet->available_balance,
                        'reference_type' => 'withdrawal',
                        'reference_id' => $withdrawal->id,
                        'description' => 'Withdrawal rejected - refund',
                        'metadata' => [],
                    ]);
                }

                return ['status' => 'rejected', 'message' => 'Withdrawal rejected and refunded'];
            }

            throw new \Exception('Invalid action');
        });
    }

    /**
     * Mock approve deposit for testing
     */
    private function mockApproveDeposit(Deposit $deposit): array
    {
        $mockTransactionId = 'MOCK_' . uniqid();
        
        return $this->approveDeposit($deposit, [
            'gateway_transaction_id' => $mockTransactionId,
            'status' => 'success',
            'mock' => true,
        ]);
    }

    /**
     * Mock reject deposit
     */
    private function rejectDeposit(Deposit $deposit, array $callbackData): array
    {
        $deposit->update([
            'status' => 'rejected',
            'gateway_response' => $callbackData,
        ]);

        return [
            'deposit_id' => $deposit->id,
            'status' => 'rejected',
        ];
    }
}
