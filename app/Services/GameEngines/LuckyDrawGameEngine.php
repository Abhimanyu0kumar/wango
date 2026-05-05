<?php

namespace App\Services\GameEngines;

use App\Models\Game;
use App\Models\GameRound;
use App\Models\LuckyDrawRound;
use App\Models\Bet;
use App\Models\Settlement;
use App\Models\WalletAccount;
use App\Models\WalletLedger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;
use App\Events\GameRoundUpdated;

class LuckyDrawGameEngine
{
    public const WINNING_SIDE_SMALL = 'small';
    public const WINNING_SIDE_DRAW = 'draw';
    public const WINNING_SIDE_BIG = 'big';

    public const STATE_WAITING = 'waiting';
    public const STATE_BETTING_OPEN = 'betting_open';
    public const STATE_LOCKED = 'locked';
    public const STATE_SETTLING = 'settling';
    public const STATE_SETTLED = 'settled';
    public const STATE_CANCELLED = 'cancelled';

    private Game $game;

    public function __construct(Game $game)
    {
        $this->game = $game;
    }

    /**
     * Start a new lucky draw round
     */
    public function startNewRound(int $durationSec = 60): LuckyDrawRound
    {
        return DB::transaction(function () use ($durationSec) {
            $now = now();
            // Close betting 5 seconds before the duration ends
            $bettingClosesAt = $now->copy()->addSeconds($durationSec - 5);
            $resultAt = $now->copy()->addSeconds($durationSec);

            // Create game round
            $gameRound = GameRound::create([
                'game_id' => $this->game->id,
                'round_code' => $this->generateRoundCode(),
                'state' => self::STATE_BETTING_OPEN,
                'starts_at' => $now,
                'betting_closes_at' => $bettingClosesAt,
                'ended_at' => $resultAt,
                'total_bet_amount' => 0,
                'total_payout_amount' => 0,
            ]);

            // Get multipliers from game metadata for this specific duration
            $metadata = $this->game->metadata ?? [];
            $durationsConfig = $metadata['durations'] ?? [];
            
            // Find multiplier config for this duration
            $durationConfig = collect($durationsConfig)->firstWhere('duration', $durationSec);
            $multipliers = $durationConfig['multipliers'] ?? [];
            
            $smallMultiplier = $multipliers['small'] ?? $metadata['small_multiplier'] ?? 1.9;
            $drawMultiplier = $multipliers['draw'] ?? $metadata['draw_multiplier'] ?? 4.5;
            $bigMultiplier = $multipliers['big'] ?? $metadata['big_multiplier'] ?? 1.9;

            // Create lucky draw round
            $luckyDrawRound = LuckyDrawRound::create([
                'game_id' => $this->game->id,
                'round_id' => $gameRound->id,
                'duration_sec' => $durationSec,
                'betting_starts_at' => $now,
                'betting_closes_at' => $bettingClosesAt,
                'result_at' => $resultAt,
                'small_multiplier' => $smallMultiplier,
                'draw_multiplier' => $drawMultiplier,
                'big_multiplier' => $bigMultiplier,
                'dice_one' => null,
                'dice_two' => null,
                'total' => null,
                'winning_side' => null,
                'result_mode' => 'automatic',
                'modified_by' => null,
                'modified_at' => null,
                'status' => self::STATE_BETTING_OPEN,
                'total_bet_amount' => 0,
                'total_payout_amount' => 0,
                'metadata' => [],
            ]);

            // Broadcast round started
            event(new GameRoundUpdated($luckyDrawRound->fresh()));

            return $luckyDrawRound;
        });
    }

    /**
     * Close betting for a round
     */
    public function closeBetting(LuckyDrawRound $luckyDrawRound): void
    {
        DB::transaction(function () use ($luckyDrawRound) {
            if ($luckyDrawRound->status !== self::STATE_BETTING_OPEN) {
                throw new Exception('Round is not in betting open state');
            }

            $luckyDrawRound->update(['status' => self::STATE_LOCKED]);
            
            $luckyDrawRound->round->update(['state' => self::STATE_LOCKED]);

            // Broadcast betting closed
            event(new GameRoundUpdated($luckyDrawRound->fresh()));
        });
    }

    /**
     * Generate automatic result for a round
     */
    public function generateResult(LuckyDrawRound $luckyDrawRound): void
    {
        DB::transaction(function () use ($luckyDrawRound) {
            if ($luckyDrawRound->status !== self::STATE_LOCKED) {
                throw new Exception('Round must be locked before generating result');
            }

            $diceOne = random_int(1, 6);
            $diceTwo = random_int(1, 6);
            $total = $diceOne + $diceTwo;
            $winningSide = $this->calculateWinningSide($total);

            $luckyDrawRound->update([
                'dice_one' => $diceOne,
                'dice_two' => $diceTwo,
                'total' => $total,
                'winning_side' => $winningSide,
                'result_mode' => 'automatic',
                'result_at' => now(),
                'status' => self::STATE_SETTLING,
            ]);

            $luckyDrawRound->round->update([
                'state' => self::STATE_SETTLING,
                'result' => [
                    'dice_one' => $diceOne,
                    'dice_two' => $diceTwo,
                    'total' => $total,
                    'winning_side' => $winningSide,
                ],
                'ended_at' => now(),
            ]);

            // Broadcast result generated
            event(new GameRoundUpdated($luckyDrawRound->fresh()));
        });
    }

    /**
     * Set manual result (admin override)
     */
    public function setManualResult(LuckyDrawRound $luckyDrawRound, int $diceOne, int $diceTwo, ?int $adminId = null): void
    {
        DB::transaction(function () use ($luckyDrawRound, $diceOne, $diceTwo, $adminId) {
            if (!in_array($luckyDrawRound->status, [self::STATE_LOCKED, self::STATE_BETTING_OPEN])) {
                throw new Exception('Round must be locked or betting open for manual result');
            }

            $total = $diceOne + $diceTwo;
            $winningSide = $this->calculateWinningSide($total);

            $luckyDrawRound->update([
                'dice_one' => $diceOne,
                'dice_two' => $diceTwo,
                'total' => $total,
                'winning_side' => $winningSide,
                'result_mode' => 'manual',
                'modified_by' => $adminId,
                'modified_at' => now(),
                'result_at' => now(),
                'status' => self::STATE_SETTLING,
            ]);

            $luckyDrawRound->round->update([
                'state' => self::STATE_SETTLING,
                'result' => [
                    'dice_one' => $diceOne,
                    'dice_two' => $diceTwo,
                    'total' => $total,
                    'winning_side' => $winningSide,
                ],
                'ended_at' => now(),
            ]);

            // Broadcast manual result
            event(new GameRoundUpdated($luckyDrawRound->fresh()));
        });
    }

    /**
     * Settle all bets for a round
     */
    public function settleRound(LuckyDrawRound $luckyDrawRound): array
    {
        return DB::transaction(function () use ($luckyDrawRound) {
            if ($luckyDrawRound->status !== self::STATE_SETTLING) {
                throw new Exception('Round must be in settling state');
            }

            if (!$luckyDrawRound->winning_side) {
                throw new Exception('Round result not generated');
            }

            $bets = Bet::where('round_id', $luckyDrawRound->round_id)
                ->where('status', 'placed')
                ->with(['user', 'wallet'])
                ->get();

            $totalPayout = 0;
            $settledCount = 0;
            $winCount = 0;
            $loseCount = 0;

            foreach ($bets as $bet) {
                $result = $this->settleBet($bet, $luckyDrawRound);
                
                if ($result['is_win']) {
                    $winCount++;
                    $totalPayout += $result['payout'];
                } else {
                    $loseCount++;
                }
                $settledCount++;
            }

            // Update round totals
            $luckyDrawRound->update([
                'status' => self::STATE_SETTLED,
                'total_payout_amount' => $totalPayout,
            ]);

            $luckyDrawRound->round->update([
                'state' => self::STATE_SETTLED,
                'total_payout_amount' => $totalPayout,
            ]);

            // Broadcast round settled
            event(new GameRoundUpdated($luckyDrawRound->fresh()));

            return [
                'total_bets' => $settledCount,
                'win_count' => $winCount,
                'lose_count' => $loseCount,
                'total_payout' => $totalPayout,
            ];
        });
    }

    /**
     * Settle individual bet
     */
    private function settleBet(Bet $bet, LuckyDrawRound $luckyDrawRound): array
    {
        $winningSide = $luckyDrawRound->winning_side;
        $selection = $bet->selection;

        // Determine if bet wins
        $isWin = false;
        if (is_array($selection)) {
            $isWin = in_array($winningSide, $selection);
        } else {
            $isWin = ($selection === $winningSide);
        }

        // Get multiplier based on winning side
        $multiplier = match ($winningSide) {
            self::WINNING_SIDE_SMALL => $luckyDrawRound->small_multiplier,
            self::WINNING_SIDE_DRAW => $luckyDrawRound->draw_multiplier,
            self::WINNING_SIDE_BIG => $luckyDrawRound->big_multiplier,
            default => 0,
        };

        $payoutAmount = $isWin ? round($bet->amount * $multiplier, 2) : 0;
        $profitLoss = $isWin ? $payoutAmount - $bet->amount : -$bet->amount;

        // Update bet
        $bet->update([
            'payout_amount' => $payoutAmount,
            'status' => $isWin ? 'won' : 'lost',
            'settled_at' => now(),
        ]);

        // Create settlement record
        Settlement::create([
            'bet_id' => $bet->id,
            'user_id' => $bet->user_id,
            'result' => $winningSide,
            'payout_amount' => $payoutAmount,
            'profit_loss' => $profitLoss,
            'settled_by' => null,
            'settled_at' => now(),
            'notes' => $isWin ? 'Win on ' . $winningSide : 'Loss on ' . $winningSide,
        ]);

        // Handle wallet transactions
        $wallet = $bet->wallet;

        if ($isWin) {
            // Unlock locked balance and add payout
            $wallet->available_balance += $payoutAmount;
            $wallet->locked_balance -= $bet->amount;
            $wallet->save();

            // Credit ledger entry
            WalletLedger::create([
                'wallet_id' => $wallet->id,
                'user_id' => $bet->user_id,
                'txn_type' => 'bet_win',
                'direction' => 'credit',
                'amount' => $payoutAmount,
                'balance_before' => $wallet->available_balance - $payoutAmount,
                'balance_after' => $wallet->available_balance,
                'reference_type' => 'bet',
                'reference_id' => $bet->id,
                'description' => "Bet win on round {$luckyDrawRound->round->round_code}",
                'metadata' => ['bet_id' => $bet->id, 'winning_side' => $winningSide],
            ]);
        } else {
            // Just unlock (funds already deducted on bet placement)
            $wallet->locked_balance -= $bet->amount;
            $wallet->save();

            // Loss ledger entry
            WalletLedger::create([
                'wallet_id' => $wallet->id,
                'user_id' => $bet->user_id,
                'txn_type' => 'bet_loss',
                'direction' => 'debit',
                'amount' => $bet->amount,
                'balance_before' => $wallet->available_balance + $bet->amount,
                'balance_after' => $wallet->available_balance,
                'reference_type' => 'bet',
                'reference_id' => $bet->id,
                'description' => "Bet loss on round {$luckyDrawRound->round->round_code}",
                'metadata' => ['bet_id' => $bet->id, 'winning_side' => $winningSide],
            ]);
        }

        return [
            'is_win' => $isWin,
            'payout' => $payoutAmount,
            'profit_loss' => $profitLoss,
        ];
    }

    /**
     * Cancel a round and refund all bets
     */
    public function cancelRound(LuckyDrawRound $luckyDrawRound): array
    {
        return DB::transaction(function () use ($luckyDrawRound) {
            if (in_array($luckyDrawRound->status, [self::STATE_SETTLED, self::STATE_CANCELLED])) {
                throw new Exception('Cannot cancel settled or already cancelled round');
            }

            $bets = Bet::where('round_id', $luckyDrawRound->round_id)
                ->whereIn('status', ['placed', 'pending'])
                ->with(['user', 'wallet'])
                ->get();

            $refundedCount = 0;
            $totalRefunded = 0;

            foreach ($bets as $bet) {
                $wallet = $bet->wallet;
                
                // Refund locked amount
                $wallet->available_balance += $bet->amount;
                $wallet->locked_balance -= $bet->amount;
                $wallet->save();

                // Ledger entry
                WalletLedger::create([
                    'wallet_id' => $wallet->id,
                    'user_id' => $bet->user_id,
                    'txn_type' => 'bet_refund',
                    'direction' => 'credit',
                    'amount' => $bet->amount,
                    'balance_before' => $wallet->available_balance - $bet->amount,
                    'balance_after' => $wallet->available_balance,
                    'reference_type' => 'bet',
                    'reference_id' => $bet->id,
                    'description' => "Bet refund on cancelled round {$luckyDrawRound->round->round_code}",
                    'metadata' => ['bet_id' => $bet->id, 'reason' => 'round_cancelled'],
                ]);

                $bet->update([
                    'status' => 'refunded',
                    'settled_at' => now(),
                ]);

                $totalRefunded += $bet->amount;
                $refundedCount++;
            }

            $luckyDrawRound->update(['status' => self::STATE_CANCELLED]);
            $luckyDrawRound->round->update(['state' => self::STATE_CANCELLED]);

            // Broadcast round cancelled
            event(new GameRoundUpdated($luckyDrawRound->fresh()));

            return [
                'refunded_count' => $refundedCount,
                'total_refunded' => $totalRefunded,
            ];
        });
    }

    /**
     * Calculate winning side based on dice total
     */
    private function calculateWinningSide(int $total): string
    {
        if ($total < 7) {
            return self::WINNING_SIDE_SMALL;
        } elseif ($total === 7) {
            return self::WINNING_SIDE_DRAW;
        } else {
            return self::WINNING_SIDE_BIG;
        }
    }

    /**
     * Generate unique round code
     */
    private function generateRoundCode(): string
    {
        $prefix = 'LD';
        $timestamp = now()->format('YmdHis');
        $random = str_pad(random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        return $prefix . $timestamp . $random;
    }

    /**
     * Get current active round or create new one
     */
    public function getCurrentOrCreateRound(int $durationSec = 60): LuckyDrawRound
    {
        $activeRound = LuckyDrawRound::where('game_id', $this->game->id)
            ->whereIn('status', [self::STATE_BETTING_OPEN, self::STATE_LOCKED])
            ->with('round')
            ->first();

        if ($activeRound) {
            return $activeRound;
        }

        return $this->startNewRound($durationSec);
    }

    /**
     * Get game statistics
     */
    public function getGameStats(): array
    {
        $gameId = $this->game->id;

        $totalRounds = LuckyDrawRound::where('game_id', $gameId)->count();
        $settledRounds = LuckyDrawRound::where('game_id', $gameId)->where('status', self::STATE_SETTLED)->count();
        $cancelledRounds = LuckyDrawRound::where('game_id', $gameId)->where('status', self::STATE_CANCELLED)->count();

        $totalBets = Bet::where('game_id', $gameId)->count();
        $totalBetAmount = Bet::where('game_id', $gameId)->sum('amount');
        $totalPayout = Settlement::whereHas('bet', fn($q) => $q->where('game_id', $gameId))->sum('payout_amount');

        return [
            'total_rounds' => $totalRounds,
            'settled_rounds' => $settledRounds,
            'cancelled_rounds' => $cancelledRounds,
            'total_bets' => $totalBets,
            'total_bet_amount' => round($totalBetAmount, 2),
            'total_payout' => round($totalPayout, 2),
            'house_profit' => round($totalBetAmount - $totalPayout, 2),
        ];
    }
}
