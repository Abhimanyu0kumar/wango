<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Game;
use Illuminate\Http\Request;

class GameController extends Controller
{
    /**
     * Display a listing of active games for users.
     */
    public function index(Request $request)
    {
        $query = Game::with('category')->where('status', 'active');

        if ($request->has('category_id')) {
            $query->where('category_id', $request->get('category_id'));
        }

        if ($request->has('provider')) {
            $query->where('provider', $request->get('provider'));
        }

        if ($request->has('engine_key')) {
            $query->where('engine_key', $request->get('engine_key'));
        }

        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $perPage = $request->get('per_page', 15);
        $games = $query->paginate($perPage);

        return response()->json([
            'data' => $games->items(),
            'meta' => [
                'current_page' => $games->currentPage(),
                'last_page' => $games->lastPage(),
                'per_page' => $games->perPage(),
                'total' => $games->total(),
            ]
        ]);
    }

    /**
     * Display the specified game.
     */
    public function show(Game $game)
    {
        if ($game->status !== 'active') {
            return response()->json(['message' => 'Game not found'], 404);
        }

        return response()->json(['data' => $game->load('category')]);
    }

    /**
     * Get the active round for a specific game and timer duration.
     */
    public function activeRound(Game $game, $duration)
    {
        if ($game->status !== 'active') {
            return response()->json(['message' => 'Game not available'], 404);
        }

        $engine = new \App\Services\GameEngines\LuckyDrawGameEngine($game);
        $round = $engine->getCurrentOrCreateRound((int)$duration);

        if (!$round) {
            return response()->json(['message' => 'Unable to fetch current round'], 500);
        }

        // Return the standard round and the lucky draw specifics
        $luckyDrawRound = \App\Models\LuckyDrawRound::where('round_id', $round->id)->first();

        return response()->json([
            'data' => [
                'round_code' => $round->round_code,
                'status' => $luckyDrawRound->status ?? $round->status,
                'starts_at' => $round->starts_at,
                'betting_closes_at' => $round->betting_closes_at,
                'ended_at' => $round->ended_at,
                'duration_sec' => (int)$duration,
                'multipliers' => [
                    'small' => $luckyDrawRound->small_multiplier ?? 1.9,
                    'draw' => $luckyDrawRound->draw_multiplier ?? 4.5,
                    'big' => $luckyDrawRound->big_multiplier ?? 1.9,
                ]
            ]
        ]);
    }

    /**
     * Get all active rounds for a game (grouped by duration)
     * GET /user/v1/games/{game}/active-rounds
     */
    public function activeRounds(Game $game)
    {
        if ($game->status !== 'active') {
            return response()->json(['message' => 'Game not available'], 404);
        }

        // Get durations from game metadata
        $metadata = $game->metadata ?? [];
        $durationsConfig = $metadata['durations'] ?? [
            ['duration' => 10, 'active' => true],
            ['duration' => 20, 'active' => true],
            ['duration' => 30, 'active' => true],
        ];

        // Filter active durations
        $activeDurations = array_filter($durationsConfig, fn($d) => ($d['active'] ?? true) === true);
        $durations = array_column($activeDurations, 'duration');

        if (empty($durations)) {
            $durations = [10, 20, 30]; // Fallback
        }

        $roundsData = [];

        foreach ($durations as $duration) {
            $luckyDrawRound = \App\Models\LuckyDrawRound::where('game_id', $game->id)
                ->where('duration_sec', $duration)
                ->whereIn('status', ['betting_open', 'locked', 'settling', 'settled'])
                ->with('round')
                ->orderBy('created_at', 'desc')
                ->first();

            if ($luckyDrawRound) {
                $roundsData[] = [
                    'duration_sec' => $duration,
                    'round_code' => $luckyDrawRound->round->round_code,
                    'status' => $luckyDrawRound->status,
                    'starts_at' => $luckyDrawRound->betting_starts_at?->toIso8601String(),
                    'betting_closes_at' => $luckyDrawRound->betting_closes_at?->toIso8601String(),
                    'result_at' => $luckyDrawRound->result_at?->toIso8601String(),
                    'ended_at' => $luckyDrawRound->round->ended_at?->toIso8601String(),
                    'multipliers' => [
                        'small' => $luckyDrawRound->small_multiplier,
                        'draw' => $luckyDrawRound->draw_multiplier,
                        'big' => $luckyDrawRound->big_multiplier,
                    ],
                    'dice_one' => $luckyDrawRound->dice_one,
                    'dice_two' => $luckyDrawRound->dice_two,
                    'total' => $luckyDrawRound->total,
                    'winning_side' => $luckyDrawRound->winning_side,
                    'total_bets' => $luckyDrawRound->round->bets()->count(),
                    'total_bet_amount' => round($luckyDrawRound->round->bets()->sum('amount'), 2),
                ];
            }
        }

        return response()->json([
            'data' => [
                'game_id' => $game->id,
                'game_name' => $game->name,
                'rounds' => $roundsData,
            ]
        ]);
    }
}
