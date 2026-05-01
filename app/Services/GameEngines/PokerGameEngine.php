<?php

namespace App\Services\GameEngines;

use App\Models\Game;
use App\Models\GameRound;
use App\Models\PokerRound;
use App\Models\Bet;
use App\Models\Settlement;
use App\Events\GameRoundUpdated;
use Illuminate\Support\Facades\DB;
use Exception;

class PokerGameEngine
{
    // Poker game states
    public const STATE_WAITING = 'waiting';
    public const STATE_PRE_FLOP = 'pre_flop';
    public const STATE_FLOP = 'flop';
    public const STATE_TURN = 'turn';
    public const STATE_RIVER = 'river';
    public const STATE_SHOWDOWN = 'showdown';
    public const STATE_SETTLING = 'settling';
    public const STATE_SETTLED = 'settled';
    public const STATE_CANCELLED = 'cancelled';

    // Winning hand types (for showdown evaluation)
    public const HAND_ROYAL_FLUSH = 'royal_flush';
    public const HAND_STRAIGHT_FLUSH = 'straight_flush';
    public const HAND_FOUR_KIND = 'four_of_a_kind';
    public const HAND_FULL_HOUSE = 'full_house';
    public const HAND_FLUSH = 'flush';
    public const HAND_STRAIGHT = 'straight';
    public const HAND_THREE_KIND = 'three_of_a_kind';
    public const HAND_TWO_PAIR = 'two_pair';
    public const HAND_ONE_PAIR = 'one_pair';
    public const HAND_HIGH_CARD = 'high_card';

    private Game $game;

    public function __construct(Game $game)
    {
        $this->game = $game;
    }

    /**
     * Start a new Poker round (simplified - house vs player)
     */
    public function startNewRound(int $durationSec = 120): PokerRound
    {
        return DB::transaction(function () use ($durationSec) {
            $now = now();
            $bettingClosesAt = $now->copy()->addSeconds($durationSec);

            // Create game round
            $gameRound = GameRound::create([
                'game_id' => $this->game->id,
                'round_code' => $this->generateRoundCode(),
                'state' => self::STATE_PRE_FLOP,
                'starts_at' => $now,
                'betting_closes_at' => $bettingClosesAt,
                'total_bet_amount' => 0,
                'total_payout_amount' => 0,
            ]);

            // Create Poker round (simplified - house acts as dealer)
            $pokerRound = PokerRound::create([
                'game_id' => $this->game->id,
                'round_id' => $gameRound->id,
                'community_cards' => null,
                'status' => self::STATE_PRE_FLOP,
                'duration_sec' => $durationSec,
                'betting_starts_at' => $now,
                'betting_closes_at' => $bettingClosesAt,
                'flop_at' => null,
                'turn_at' => null,
                'river_at' => null,
                'showdown_at' => null,
                'metadata' => [],
            ]);

            // Broadcast round started
            event(new GameRoundUpdated($pokerRound->fresh()));

            return $pokerRound;
        });
    }

    /**
     * Progress to Flop (first 3 community cards)
     */
    public function dealFlop(PokerRound $pokerRound): void
    {
        DB::transaction(function () use ($pokerRound) {
            if ($pokerRound->status !== self::STATE_PRE_FLOP) {
                throw new Exception('Round must be in pre-flop state');
            }

            $deck = $this->createDeck();
            shuffle($deck);
            
            $flopCards = array_slice($deck, 0, 3);
            
            $pokerRound->update([
                'community_cards' => $flopCards,
                'status' => self::STATE_FLOP,
                'flop_at' => now(),
            ]);

            $pokerRound->round->update(['state' => self::STATE_FLOP]);

            // Broadcast flop dealt
            event(new GameRoundUpdated($pokerRound->fresh()));
        });
    }

    /**
     * Progress to Turn (4th community card)
     */
    public function dealTurn(PokerRound $pokerRound): void
    {
        DB::transaction(function () use ($pokerRound) {
            if ($pokerRound->status !== self::STATE_FLOP) {
                throw new Exception('Round must be in flop state');
            }

            $communityCards = $pokerRound->community_cards ?? [];
            $deck = $this->createDeck();
            shuffle($deck);
            
            $turnCard = $deck[0];
            $communityCards[] = $turnCard;
            
            $pokerRound->update([
                'community_cards' => $communityCards,
                'status' => self::STATE_TURN,
                'turn_at' => now(),
            ]);

            $pokerRound->round->update(['state' => self::STATE_TURN]);

            // Broadcast turn dealt
            event(new GameRoundUpdated($pokerRound->fresh()));
        });
    }

    /**
     * Progress to River (5th community card)
     */
    public function dealRiver(PokerRound $pokerRound): void
    {
        DB::transaction(function () use ($pokerRound) {
            if ($pokerRound->status !== self::STATE_TURN) {
                throw new Exception('Round must be in turn state');
            }

            $communityCards = $pokerRound->community_cards ?? [];
            $deck = $this->createDeck();
            shuffle($deck);
            
            $riverCard = $deck[0];
            $communityCards[] = $riverCard;
            
            $pokerRound->update([
                'community_cards' => $communityCards,
                'status' => self::STATE_RIVER,
                'river_at' => now(),
            ]);

            $pokerRound->round->update(['state' => self::STATE_RIVER]);

            // Broadcast river dealt
            event(new GameRoundUpdated($pokerRound->fresh()));
        });
    }

    /**
     * Evaluate hands and determine winner (simplified - compares player hand vs house hand)
     */
    public function evaluateShowdown(PokerRound $pokerRound): void
    {
        DB::transaction(function () use ($pokerRound) {
            if ($pokerRound->status !== self::STATE_RIVER) {
                throw new Exception('Round must be in river state');
            }

            // This is a simplified version - just generates a "house hand" result
            // In full poker, you'd evaluate each player's 2 hole cards + 5 community cards
            $houseHandType = $this->evaluateSimplifiedHand($pokerRound->community_cards ?? []);

            $pokerRound->update([
                'status' => self::STATE_SHOWDOWN,
                'showdown_at' => now(),
                'metadata' => array_merge($pokerRound->metadata ?? [], [
                    'house_hand_type' => $houseHandType,
                ]),
            ]);

            $pokerRound->round->update([
                'state' => self::STATE_SHOWDOWN,
                'result' => ['house_hand_type' => $houseHandType],
            ]);

            // Broadcast showdown
            event(new GameRoundUpdated($pokerRound->fresh()));
        });
    }

    /**
     * Settle bets (simplified - player wins if they bet on correct outcome)
     */
    public function settleRound(PokerRound $pokerRound): array
    {
        return DB::transaction(function () use ($pokerRound) {
            if ($pokerRound->status !== self::STATE_SHOWDOWN) {
                throw new Exception('Round must be in showdown state');
            }

            $bets = Bet::where('round_id', $pokerRound->round_id)
                ->where('status', 'placed')
                ->with(['user', 'wallet'])
                ->get();

            $totalPayout = 0;
            $settledCount = 0;
            $winCount = 0;

            foreach ($bets as $bet) {
                // Simplified: if player bet on "house_wins" and house has good hand, they win
                $isWin = $this->evaluateBet($bet, $pokerRound);
                $multiplier = $this->getMultiplier($bet->selection, $pokerRound);
                $payoutAmount = $isWin ? round($bet->amount * $multiplier, 2) : 0;

                $bet->update([
                    'payout_amount' => $payoutAmount,
                    'status' => $isWin ? 'won' : 'lost',
                    'settled_at' => now(),
                ]);

                Settlement::create([
                    'bet_id' => $bet->id,
                    'user_id' => $bet->user_id,
                    'result' => $pokerRound->metadata['house_hand_type'] ?? 'unknown',
                    'payout_amount' => $payoutAmount,
                    'profit_loss' => $isWin ? $payoutAmount - $bet->amount : -$bet->amount,
                    'settled_by' => null,
                    'settled_at' => now(),
                    'notes' => $isWin ? 'Win' : 'Loss',
                ]);

                if ($isWin) {
                    $wallet = $bet->wallet;
                    $wallet->available_balance += $payoutAmount;
                    $wallet->locked_balance -= $bet->amount;
                    $wallet->save();
                    $totalPayout += $payoutAmount;
                    $winCount++;
                }

                $settledCount++;
            }

            $pokerRound->update(['status' => self::STATE_SETTLED]);
            $pokerRound->round->update([
                'state' => self::STATE_SETTLED,
                'total_payout_amount' => $totalPayout,
            ]);

            // Broadcast settled
            event(new GameRoundUpdated($pokerRound->fresh()));

            return [
                'total_bets' => $settledCount,
                'win_count' => $winCount,
                'total_payout' => $totalPayout,
            ];
        });
    }

    /**
     * Cancel round and refund
     */
    public function cancelRound(PokerRound $pokerRound): array
    {
        return DB::transaction(function () use ($pokerRound) {
            $bets = Bet::where('round_id', $pokerRound->round_id)
                ->whereIn('status', ['placed', 'pending'])
                ->with('wallet')
                ->get();

            $refundedCount = 0;
            $totalRefunded = 0;

            foreach ($bets as $bet) {
                $wallet = $bet->wallet;
                $wallet->available_balance += $bet->amount;
                $wallet->locked_balance -= $bet->amount;
                $wallet->save();
                $bet->update(['status' => 'refunded', 'settled_at' => now()]);
                $totalRefunded += $bet->amount;
                $refundedCount++;
            }

            $pokerRound->update(['status' => self::STATE_CANCELLED]);
            $pokerRound->round->update(['state' => self::STATE_CANCELLED]);

            event(new GameRoundUpdated($pokerRound->fresh()));

            return ['refunded_count' => $refundedCount, 'total_refunded' => $totalRefunded];
        });
    }

    /**
     * Create a standard 52-card deck
     */
    private function createDeck(): array
    {
        $suits = ['♠', '♥', '♦', '♣'];
        $values = ['2', '3', '4', '5', '6', '7', '8', '9', '10', 'J', 'Q', 'K', 'A'];
        $deck = [];
        
        foreach ($suits as $suit) {
            foreach ($values as $value) {
                $deck[] = $value . $suit;
            }
        }
        
        return $deck;
    }

    /**
     * Simplified hand evaluation
     */
    private function evaluateSimplifiedHand(array $communityCards): string
    {
        // This is a placeholder - real poker hand evaluation is complex
        $rand = random_int(1, 100);
        
        if ($rand <= 2) return self::HAND_ROYAL_FLUSH;
        if ($rand <= 5) return self::HAND_STRAIGHT_FLUSH;
        if ($rand <= 10) return self::HAND_FOUR_KIND;
        if ($rand <= 18) return self::HAND_FULL_HOUSE;
        if ($rand <= 30) return self::HAND_FLUSH;
        if ($rand <= 45) return self::HAND_STRAIGHT;
        if ($rand <= 60) return self::HAND_THREE_KIND;
        if ($rand <= 80) return self::HAND_TWO_PAIR;
        if ($rand <= 95) return self::HAND_ONE_PAIR;
        return self::HAND_HIGH_CARD;
    }

    /**
     * Evaluate if bet wins (simplified)
     */
    private function evaluateBet(Bet $bet, PokerRound $pokerRound): bool
    {
        $selection = is_array($bet->selection) ? $bet->selection : [$bet->selection];
        $houseHand = $pokerRound->metadata['house_hand_type'] ?? 'high_card';
        
        return in_array($houseHand, $selection);
    }

    /**
     * Get multiplier based on bet selection
     */
    private function getMultiplier($selection, PokerRound $pokerRound): float
    {
        $metadata = $this->game->metadata ?? [];
        $multipliers = $metadata['poker_multipliers'] ?? [
            self::HAND_ROYAL_FLUSH => 50.0,
            self::HAND_STRAIGHT_FLUSH => 25.0,
            self::HAND_FOUR_KIND => 15.0,
            self::HAND_FULL_HOUSE => 10.0,
            self::HAND_FLUSH => 8.0,
            self::HAND_STRAIGHT => 6.0,
            self::HAND_THREE_KIND => 4.0,
            self::HAND_TWO_PAIR => 3.0,
            self::HAND_ONE_PAIR => 2.0,
            self::HAND_HIGH_CARD => 1.5,
        ];
        
        $houseHand = $pokerRound->metadata['house_hand_type'] ?? 'high_card';
        return $multipliers[$houseHand] ?? 1.0;
    }

    /**
     * Generate unique round code
     */
    private function generateRoundCode(): string
    {
        $prefix = 'PK';
        $timestamp = now()->format('YmdHis');
        $random = str_pad(random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        return $prefix . $timestamp . $random;
    }

    /**
     * Get current active round or create new one
     */
    public function getCurrentOrCreateRound(int $durationSec = 120): PokerRound
    {
        $activeRound = PokerRound::where('game_id', $this->game->id)
            ->whereIn('status', [self::STATE_PRE_FLOP, self::STATE_FLOP, self::STATE_TURN, self::STATE_RIVER])
            ->with('round')
            ->first();

        if ($activeRound) {
            return $activeRound;
        }

        return $this->startNewRound($durationSec);
    }
}
