<?php

namespace App\Services\GameEngines;

use App\Models\Game;
use App\Models\GameRound;
use App\Models\TeenPattiRound;
use App\Models\Bet;
use App\Models\Settlement;
use App\Models\WalletAccount;
use App\Models\WalletLedger;
use App\Events\GameRoundUpdated;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class TeenPattiGameEngine
{
    // Hand types
    public const HAND_TRAIL = 'trail';         // Three of a kind
    public const HAND_SEQUENCE = 'sequence';    // Straight
    public const HAND_COLOR = 'color';         // Flush
    public const HAND_PAIR = 'pair';          // Pair
    public const HAND_HIGH_CARD = 'high_card'; // High card

    // Bet types
    public const BET_PAIR_PLUS = 'pair_plus';
    public const BET_COLOR = 'color';
    public const BET_SEQUENCE = 'sequence';
    public const BET_TRAIL = 'trail';

    // States
    public const STATE_WAITING = 'waiting';
    public const STATE_BETTING_OPEN = 'betting_open';
    public const STATE_LOCKED = 'locked';
    public const STATE_SETTLING = 'settling';
    public const STATE_SETTLED = 'settled';
    public const STATE_CANCELLED = 'cancelled';

    // Card suits and values
    private const SUITS = ['♠', '♥', '♦', '♣'];
    private const VALUES = ['A', '2', '3', '4', '5', '6', '7', '8', '9', '10', 'J', 'Q', 'K'];
    private const VALUE_RANKS = [
        'A' => 14, 'K' => 13, 'Q' => 12, 'J' => 11, '10' => 10,
        '9' => 9, '8' => 8, '7' => 7, '6' => 6, '5' => 5,
        '4' => 4, '3' => 3, '2' => 2
    ];

    private Game $game;

    public function __construct(Game $game)
    {
        $this->game = $game;
    }

    /**
     * Start a new Teen Patti round
     */
    public function startNewRound(int $durationSec = 60): TeenPattiRound
    {
        return DB::transaction(function () use ($durationSec) {
            $now = now();
            $bettingClosesAt = $now->copy()->addSeconds($durationSec);

            // Get default multipliers from game metadata
            $metadata = $this->game->metadata ?? [];
            $multipliers = [
                'pair_plus' => $metadata['pair_plus_multiplier'] ?? 3.0,
                'color' => $metadata['color_multiplier'] ?? 2.0,
                'sequence' => $metadata['sequence_multiplier'] ?? 4.0,
                'trail' => $metadata['trail_multiplier'] ?? 10.0,
            ];

            // Create game round
            $gameRound = GameRound::create([
                'game_id' => $this->game->id,
                'round_code' => $this->generateRoundCode(),
                'state' => self::STATE_BETTING_OPEN,
                'starts_at' => $now,
                'betting_closes_at' => $bettingClosesAt,
                'total_bet_amount' => 0,
                'total_payout_amount' => 0,
            ]);

            // Create Teen Patti round
            $teenPattiRound = TeenPattiRound::create([
                'game_id' => $this->game->id,
                'round_id' => $gameRound->id,
                'cards' => null,
                'hand_type' => null,
                'hand_rank' => null,
                'winning_bet_type' => null,
                'multipliers' => $multipliers,
                'duration_sec' => $durationSec,
                'betting_starts_at' => $now,
                'betting_closes_at' => $bettingClosesAt,
                'result_at' => null,
                'metadata' => [],
                'status' => self::STATE_BETTING_OPEN,
            ]);

            // Broadcast round started
            event(new GameRoundUpdated($teenPattiRound->fresh()));

            return $teenPattiRound;
        });
    }

    /**
     * Close betting for a round
     */
    public function closeBetting(TeenPattiRound $teenPattiRound): void
    {
        DB::transaction(function () use ($teenPattiRound) {
            if ($teenPattiRound->status !== self::STATE_BETTING_OPEN) {
                throw new Exception('Round is not in betting open state');
            }

            $teenPattiRound->update(['status' => self::STATE_LOCKED]);
            $teenPattiRound->round->update(['state' => self::STATE_LOCKED]);

            // Broadcast betting closed
            event(new GameRoundUpdated($teenPattiRound->fresh()));
        });
    }

    /**
     * Generate automatic result (deal cards and evaluate)
     */
    public function generateResult(TeenPattiRound $teenPattiRound): void
    {
        DB::transaction(function () use ($teenPattiRound) {
            if ($teenPattiRound->status !== self::STATE_LOCKED) {
                throw new Exception('Round must be locked before generating result');
            }

            // Deal 3 cards
            $cards = $this->dealCards();
            $handType = $this->evaluateHand($cards);
            $handRank = $this->calculateHandRank($cards, $handType);
            $winningBetType = $this->getWinningBetType($handType);

            $teenPattiRound->update([
                'cards' => $cards,
                'hand_type' => $handType,
                'hand_rank' => $handRank,
                'winning_bet_type' => $winningBetType,
                'result_at' => now(),
                'status' => self::STATE_SETTLING,
            ]);

            $teenPattiRound->round->update([
                'state' => self::STATE_SETTLING,
                'result' => [
                    'cards' => $cards,
                    'hand_type' => $handType,
                    'hand_rank' => $handRank,
                    'winning_bet_type' => $winningBetType,
                ],
                'ended_at' => now(),
            ]);

            // Broadcast result generated
            event(new GameRoundUpdated($teenPattiRound->fresh()));
        });
    }

    /**
     * Set manual result (admin override)
     */
    public function setManualResult(TeenPattiRound $teenPattiRound, array $cards): void
    {
        DB::transaction(function () use ($teenPattiRound, $cards) {
            if (!in_array($teenPattiRound->status, [self::STATE_LOCKED, self::STATE_BETTING_OPEN])) {
                throw new Exception('Round must be locked or betting open for manual result');
            }

            if (count($cards) !== 3) {
                throw new Exception('Must provide exactly 3 cards');
            }

            $handType = $this->evaluateHand($cards);
            $handRank = $this->calculateHandRank($cards, $handType);
            $winningBetType = $this->getWinningBetType($handType);

            $teenPattiRound->update([
                'cards' => $cards,
                'hand_type' => $handType,
                'hand_rank' => $handRank,
                'winning_bet_type' => $winningBetType,
                'result_at' => now(),
                'status' => self::STATE_SETTLING,
            ]);

            $teenPattiRound->round->update([
                'state' => self::STATE_SETTLING,
                'result' => [
                    'cards' => $cards,
                    'hand_type' => $handType,
                    'hand_rank' => $handRank,
                    'winning_bet_type' => $winningBetType,
                    'is_manual' => true,
                ],
                'ended_at' => now(),
            ]);

            // Broadcast result generated
            event(new GameRoundUpdated($teenPattiRound->fresh()));
        });
    }

    /**
     * Settle all bets for a round
     */
    public function settleRound(TeenPattiRound $teenPattiRound): array
    {
        return DB::transaction(function () use ($teenPattiRound) {
            if ($teenPattiRound->status !== self::STATE_SETTLING) {
                throw new Exception('Round must be in settling state');
            }

            if (!$teenPattiRound->winning_bet_type) {
                throw new Exception('Round result not generated');
            }

            $bets = Bet::where('round_id', $teenPattiRound->round_id)
                ->where('status', 'placed')
                ->with(['user', 'wallet'])
                ->get();

            $totalPayout = 0;
            $settledCount = 0;
            $winCount = 0;
            $loseCount = 0;

            foreach ($bets as $bet) {
                $result = $this->settleBet($bet, $teenPattiRound);
                
                if ($result['is_win']) {
                    $winCount++;
                    $totalPayout += $result['payout'];
                } else {
                    $loseCount++;
                }
                $settledCount++;
            }

            // Update round totals
            $teenPattiRound->update([
                'status' => self::STATE_SETTLED,
            ]);

            $teenPattiRound->round->update([
                'state' => self::STATE_SETTLED,
                'total_payout_amount' => $totalPayout,
            ]);

            // Broadcast round settled
            event(new GameRoundUpdated($teenPattiRound->fresh()));

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
    private function settleBet(Bet $bet, TeenPattiRound $teenPattiRound): array
    {
        $selection = is_array($bet->selection) ? $bet->selection : [$bet->selection];
        $winningBetType = $teenPattiRound->winning_bet_type;
        
        // Check if bet wins (if selection includes winning bet type)
        $isWin = in_array($winningBetType, $selection);
        
        // Get multiplier for winning bet type
        $multipliers = $teenPattiRound->multipliers ?? [];
        $multiplier = $multipliers[$winningBetType] ?? 0;
        
        // For specific bet types, check if user bet on that specific type
        $specificWin = false;
        foreach ($selection as $sel) {
            if ($sel === $winningBetType) {
                $specificWin = true;
                break;
            }
        }
        
        $payoutAmount = $specificWin ? round($bet->amount * $multiplier, 2) : 0;
        $profitLoss = $specificWin ? $payoutAmount - $bet->amount : -$bet->amount;

        // Update bet
        $bet->update([
            'payout_amount' => $payoutAmount,
            'status' => $specificWin ? 'won' : 'lost',
            'settled_at' => now(),
        ]);

        // Create settlement record
        Settlement::create([
            'bet_id' => $bet->id,
            'user_id' => $bet->user_id,
            'result' => $winningBetType,
            'payout_amount' => $payoutAmount,
            'profit_loss' => $profitLoss,
            'settled_by' => null,
            'settled_at' => now(),
            'notes' => $specificWin ? 'Win on ' . $winningBetType : 'Loss on ' . $winningBetType,
        ]);

        // Handle wallet transactions
        $wallet = $bet->wallet;

        if ($specificWin) {
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
                'description' => "Bet win on {$winningBetType} - Round {$teenPattiRound->round->round_code}",
                'metadata' => ['bet_id' => $bet->id, 'winning_bet_type' => $winningBetType],
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
                'description' => "Bet loss - Round {$teenPattiRound->round->round_code}",
                'metadata' => ['bet_id' => $bet->id, 'winning_bet_type' => $winningBetType],
            ]);
        }

        return [
            'is_win' => $specificWin,
            'payout' => $payoutAmount,
            'profit_loss' => $profitLoss,
        ];
    }

    /**
     * Cancel a round and refund all bets
     */
    public function cancelRound(TeenPattiRound $teenPattiRound): array
    {
        return DB::transaction(function () use ($teenPattiRound) {
            if (in_array($teenPattiRound->status, [self::STATE_SETTLED, self::STATE_CANCELLED])) {
                throw new Exception('Cannot cancel settled or already cancelled round');
            }

            $bets = Bet::where('round_id', $teenPattiRound->round_id)
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
                    'description' => "Bet refund on cancelled round {$teenPattiRound->round->round_code}",
                    'metadata' => ['bet_id' => $bet->id, 'reason' => 'round_cancelled'],
                ]);

                $bet->update([
                    'status' => 'refunded',
                    'settled_at' => now(),
                ]);

                $totalRefunded += $bet->amount;
                $refundedCount++;
            }

            $teenPattiRound->update(['status' => self::STATE_CANCELLED]);
            $teenPattiRound->round->update(['state' => self::STATE_CANCELLED]);

            // Broadcast round cancelled
            event(new GameRoundUpdated($teenPattiRound->fresh()));

            return [
                'refunded_count' => $refundedCount,
                'total_refunded' => $totalRefunded,
            ];
        });
    }

    /**
     * Deal 3 random cards
     */
    private function dealCards(): array
    {
        $deck = [];
        foreach (self::SUITS as $suit) {
            foreach (self::VALUES as $value) {
                $deck[] = $value . $suit;
            }
        }

        shuffle($deck);
        return array_slice($deck, 0, 3);
    }

    /**
     * Evaluate the hand type from 3 cards
     */
    private function evaluateHand(array $cards): string
    {
        $values = [];
        $suits = [];
        
        foreach ($cards as $card) {
            $values[] = substr($card, 0, -1); // Remove suit
            $suits[] = substr($card, -1); // Get suit
        }

        $uniqueValues = array_unique($values);
        $uniqueSuits = array_unique($suits);

        // Check for trail (three of a kind)
        if (count($uniqueValues) === 1) {
            return self::HAND_TRAIL;
        }

        // Check for sequence (straight)
        $sortedRanks = array_map(fn($v) => self::VALUE_RANKS[$v], $values);
        sort($sortedRanks);
        
        $isSequence = false;
        if ($sortedRanks[2] - $sortedRanks[1] === 1 && $sortedRanks[1] - $sortedRanks[0] === 1) {
            $isSequence = true;
        }
        // Special case: A-2-3
        if ($sortedRanks == [2, 3, 14]) {
            $isSequence = true;
        }

        // Check for color (flush)
        $isColor = count($uniqueSuits) === 1;

        if ($isSequence && $isColor) {
            return self::HAND_SEQUENCE; // Actually a straight flush, but we treat as sequence
        }

        if ($isSequence) {
            return self::HAND_SEQUENCE;
        }

        if ($isColor) {
            return self::HAND_COLOR;
        }

        // Check for pair
        if (count($uniqueValues) === 2) {
            return self::HAND_PAIR;
        }

        return self::HAND_HIGH_CARD;
    }

    /**
     * Calculate numeric hand rank for comparison
     */
    private function calculateHandRank(array $cards, string $handType): int
    {
        $rank = 0;
        
        // Base rank by hand type
        $typeRanks = [
            self::HAND_HIGH_CARD => 100,
            self::HAND_PAIR => 200,
            self::HAND_COLOR => 300,
            self::HAND_SEQUENCE => 400,
            self::HAND_TRAIL => 500,
        ];
        
        $rank += $typeRanks[$handType] ?? 0;
        
        // Add card values for tie-breaking
        $values = array_map(fn($c) => self::VALUE_RANKS[substr($c, 0, -1)], $cards);
        rsort($values);
        
        $rank += $values[0] * 10 + $values[1] + $values[2] * 0.1;
        
        return (int) $rank;
    }

    /**
     * Get winning bet type based on hand type
     */
    private function getWinningBetType(string $handType): string
    {
        return match ($handType) {
            self::HAND_TRAIL => self::BET_TRAIL,
            self::HAND_SEQUENCE => self::BET_SEQUENCE,
            self::HAND_COLOR => self::BET_COLOR,
            default => self::BET_PAIR_PLUS, // Includes pair and high card
        };
    }

    /**
     * Generate unique round code
     */
    private function generateRoundCode(): string
    {
        $prefix = 'TP';
        $timestamp = now()->format('YmdHis');
        $random = str_pad(random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        return $prefix . $timestamp . $random;
    }

    /**
     * Get current active round or create new one
     */
    public function getCurrentOrCreateRound(int $durationSec = 60): TeenPattiRound
    {
        $activeRound = TeenPattiRound::where('game_id', $this->game->id)
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

        $totalRounds = TeenPattiRound::where('game_id', $gameId)->count();
        $settledRounds = TeenPattiRound::where('game_id', $gameId)->where('status', self::STATE_SETTLED)->count();
        $cancelledRounds = TeenPattiRound::where('game_id', $gameId)->where('status', self::STATE_CANCELLED)->count();

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
