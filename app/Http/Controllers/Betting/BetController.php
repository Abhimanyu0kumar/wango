<?php

namespace App\Http\Controllers\Betting;

use App\Http\Controllers\Controller;
use App\Models\Bet;
use App\Models\Game;
use App\Models\WalletAccount;
use App\Models\WalletLedger;
use App\Services\GameEngines\LuckyDrawGameEngine;
use App\Services\GameEngines\TeenPattiGameEngine;
use App\Services\GameEngines\PokerGameEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class BetController extends Controller
{
    /**
     * Get market odds for available games
     */
    public function odds(Request $request)
    {
        $gameId = $request->get('game_id');
        
        $query = Game::where('status', 'active');
        
        if ($gameId) {
            $query->where('id', $gameId);
        }
        
        $games = $query->with('category')->get();
        
        $odds = [];
        foreach ($games as $game) {
            $metadata = $game->metadata ?? [];
            
            if ($game->engine_key === 'dice') {
                $odds[] = [
                    'game_id' => $game->id,
                    'game_name' => $game->name,
                    'engine_key' => 'dice',
                    'min_bet' => $game->min_bet,
                    'max_bet' => $game->max_bet,
                    'bet_types' => ['small', 'draw', 'big'],
                    'multipliers' => [
                        'small' => $metadata['small_multiplier'] ?? 1.9,
                        'draw' => $metadata['draw_multiplier'] ?? 4.5,
                        'big' => $metadata['big_multiplier'] ?? 1.9,
                    ],
                ];
            } elseif ($game->engine_key === 'teenpatti') {
                $odds[] = [
                    'game_id' => $game->id,
                    'game_name' => $game->name,
                    'engine_key' => 'teenpatti',
                    'min_bet' => $game->min_bet,
                    'max_bet' => $game->max_bet,
                    'bet_types' => ['pair_plus', 'color', 'sequence', 'trail'],
                    'multipliers' => [
                        'pair_plus' => $metadata['pair_plus_multiplier'] ?? 3.0,
                        'color' => $metadata['color_multiplier'] ?? 2.0,
                        'sequence' => $metadata['sequence_multiplier'] ?? 4.0,
                        'trail' => $metadata['trail_multiplier'] ?? 10.0,
                    ],
                ];
            } elseif ($game->engine_key === 'poker') {
                $odds[] = [
                    'game_id' => $game->id,
                    'game_name' => $game->name,
                    'engine_key' => 'poker',
                    'min_bet' => $game->min_bet,
                    'max_bet' => $game->max_bet,
                    'bet_types' => ['royal_flush', 'straight_flush', 'four_of_a_kind', 'full_house', 'flush', 'straight', 'three_of_a_kind', 'two_pair', 'one_pair', 'high_card'],
                    'multipliers' => $metadata['poker_multipliers'] ?? [
                        'royal_flush' => 50.0,
                        'straight_flush' => 25.0,
                        'four_of_a_kind' => 15.0,
                        'full_house' => 10.0,
                        'flush' => 8.0,
                        'straight' => 6.0,
                        'three_of_a_kind' => 4.0,
                        'two_pair' => 3.0,
                        'one_pair' => 2.0,
                        'high_card' => 1.5,
                    ],
                ];
            }
        }
        
        return response()->json([
            'data' => $odds,
        ]);
    }

    /**
     * Place a bet
     */
    public function place(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'game_id' => 'required|exists:games,id',
            'amount' => 'required|numeric|min:1',
            'selection' => 'required',
            'idempotency_key' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = Auth::guard('api')->user();
        $data = $validator->validated();

        // Check idempotency
        if (!empty($data['idempotency_key'])) {
            $existingBet = Bet::where('user_id', $user->id)
                ->where('metadata->idempotency_key', $data['idempotency_key'])
                ->first();
            
            if ($existingBet) {
                return response()->json([
                    'message' => 'Bet already placed with this key',
                    'data' => $existingBet,
                ]);
            }
        }

        $game = Game::where('id', $data['game_id'])
            ->where('status', 'active')
            ->first();

        if (!$game) {
            return response()->json(['message' => 'Game not available'], 404);
        }

        // Validate bet amount
        if ($data['amount'] < $game->min_bet || $data['amount'] > $game->max_bet) {
            return response()->json([
                'message' => 'Bet amount must be between ' . $game->min_bet . ' and ' . $game->max_bet,
            ], 422);
        }

        // Get active round based on game type
        $round = $this->getActiveRound($game);
        if (!$round || $round->status !== 'betting_open') {
            return response()->json(['message' => 'Betting is not open for this round'], 422);
        }

        $wallet = WalletAccount::where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        if (!$wallet) {
            return response()->json(['message' => 'No active wallet found'], 404);
        }

        if ($wallet->available_balance < $data['amount']) {
            return response()->json(['message' => 'Insufficient balance'], 422);
        }

        // Calculate potential win
        $multiplier = $this->getMultiplier($game, $round, $data['selection']);
        $potentialWin = round($data['amount'] * $multiplier, 2);

        try {
            return DB::transaction(function () use ($user, $wallet, $game, $round, $data, $potentialWin) {
                // Lock wallet balance
                $wallet->available_balance -= $data['amount'];
                $wallet->locked_balance += $data['amount'];
                $wallet->save();

                // Create ledger entry
                WalletLedger::create([
                    'wallet_id' => $wallet->id,
                    'user_id' => $user->id,
                    'txn_type' => 'bet_placed',
                    'direction' => 'debit',
                    'amount' => $data['amount'],
                    'balance_before' => $wallet->available_balance + $data['amount'],
                    'balance_after' => $wallet->available_balance,
                    'reference_type' => 'game',
                    'reference_id' => $game->id,
                    'description' => 'Bet placed on ' . (is_array($data['selection']) ? implode(',', $data['selection']) : $data['selection']),
                    'metadata' => [
                        'round_id' => $round->round_id ?? $round->id,
                        'selection' => $data['selection'],
                        'idempotency_key' => $data['idempotency_key'] ?? null,
                    ],
                ]);

                // Create bet
                $bet = Bet::create([
                    'user_id' => $user->id,
                    'wallet_id' => $wallet->id,
                    'game_id' => $game->id,
                    'round_id' => $round->round_id ?? $round->id,
                    'bet_code' => 'BET-' . Str::random(10),
                    'amount' => $data['amount'],
                    'odds' => $multiplier,
                    'selection' => $data['selection'],
                    'potential_win' => $potentialWin,
                    'payout_amount' => 0,
                    'status' => 'placed',
                    'placed_at' => now(),
                    'settled_at' => null,
                    'metadata' => [
                        'idempotency_key' => $data['idempotency_key'] ?? null,
                    ],
                ]);

                // Update round total bet amount
                $this->updateRoundTotal($round, $data['amount']);

                return response()->json([
                    'message' => 'Bet placed successfully',
                    'data' => [
                        'bet_id' => $bet->id,
                        'bet_code' => $bet->bet_code,
                        'amount' => $bet->amount,
                        'selection' => $bet->selection,
                        'potential_win' => $potentialWin,
                        'round_code' => $round->round->round_code ?? 'N/A',
                        'status' => $bet->status,
                    ],
                ], 201);
            });
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to place bet: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Get active round based on game type
     */
    private function getActiveRound(Game $game)
    {
        return match ($game->engine_key) {
            'dice' => (new LuckyDrawGameEngine($game))->getCurrentOrCreateRound(60),
            'teenpatti' => (new TeenPattiGameEngine($game))->getCurrentOrCreateRound(60),
            'poker' => (new PokerGameEngine($game))->getCurrentOrCreateRound(120),
            default => throw new \Exception('Unsupported game type'),
        };
    }

    /**
     * Get multiplier for bet selection
     */
    private function getMultiplier(Game $game, $round, $selection): float
    {
        $selectionStr = is_array($selection) ? $selection[0] : $selection;
        
        return match ($game->engine_key) {
            'dice' => match ($selectionStr) {
                'small' => $round->small_multiplier ?? 1.9,
                'draw' => $round->draw_multiplier ?? 4.5,
                'big' => $round->big_multiplier ?? 1.9,
                default => 1.0,
            },
            'teenpatti' => $round->multipliers[$selectionStr] ?? 2.0,
            'poker' => 2.0, // Simplified
            default => 1.0,
        };
    }

    /**
     * Update round total bet amount
     */
    private function updateRoundTotal($round, float $amount): void
    {
        if (method_exists($round, 'increment')) {
            $round->increment('total_bet_amount', $amount);
            if ($round->round) {
                $round->round->increment('total_bet_amount', $amount);
            }
        }
    }

    /**
     * Cancel a bet and refund
     */
    public function cancel(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'bet_id' => 'required|exists:bets,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = Auth::guard('api')->user();
        $betId = $validator->validated()['bet_id'];

        $bet = Bet::where('id', $betId)
            ->where('user_id', $user->id)
            ->where('status', 'placed')
            ->with('round')
            ->first();

        if (!$bet) {
            return response()->json(['message' => 'Bet not found or cannot be cancelled'], 404);
        }

        // Check if round is still in betting
        $luckyDrawRound = \App\Models\LuckyDrawRound::where('round_id', $bet->round_id)->first();
        if ($luckyDrawRound && $luckyDrawRound->status !== 'betting_open') {
            return response()->json(['message' => 'Betting is closed for this round'], 422);
        }

        try {
            return DB::transaction(function () use ($bet, $user) {
                $wallet = WalletAccount::where('user_id', $user->id)->first();
                
                // Refund
                $wallet->available_balance += $bet->amount;
                $wallet->locked_balance -= $bet->amount;
                $wallet->save();

                // Ledger entry
                WalletLedger::create([
                    'wallet_id' => $wallet->id,
                    'user_id' => $user->id,
                    'txn_type' => 'bet_refund',
                    'direction' => 'credit',
                    'amount' => $bet->amount,
                    'balance_before' => $wallet->available_balance - $bet->amount,
                    'balance_after' => $wallet->available_balance,
                    'reference_type' => 'bet',
                    'reference_id' => $bet->id,
                    'description' => 'Bet cancelled and refunded',
                    'metadata' => ['bet_id' => $bet->id],
                ]);

                $bet->update([
                    'status' => 'cancelled',
                    'settled_at' => now(),
                ]);

                // Update round totals
                $luckyDrawRound = \App\Models\LuckyDrawRound::where('round_id', $bet->round_id)->first();
                if ($luckyDrawRound) {
                    $luckyDrawRound->decrement('total_bet_amount', $bet->amount);
                    $luckyDrawRound->round->decrement('total_bet_amount', $bet->amount);
                }

                return response()->json([
                    'message' => 'Bet cancelled and refunded',
                    'data' => [
                        'bet_id' => $bet->id,
                        'refunded_amount' => $bet->amount,
                    ],
                ]);
            });
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to cancel bet: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Get betting history
     */
    public function history(Request $request)
    {
        $user = Auth::guard('api')->user();
        
        $query = Bet::where('user_id', $user->id)
            ->with(['game', 'round', 'settlement']);

        if ($request->has('status')) {
            $query->where('status', $request->get('status'));
        }

        if ($request->has('game_id')) {
            $query->where('game_id', $request->get('game_id'));
        }

        $perPage = $request->get('per_page', 15);
        $bets = $query->orderBy('placed_at', 'desc')->paginate($perPage);

        return response()->json([
            'data' => $bets->items(),
            'meta' => [
                'current_page' => $bets->currentPage(),
                'last_page' => $bets->lastPage(),
                'per_page' => $bets->perPage(),
                'total' => $bets->total(),
            ],
        ]);
    }
}
