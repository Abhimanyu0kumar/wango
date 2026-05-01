<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PokerRound;
use App\Services\GameEngines\PokerGameEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PokerRoundController extends Controller
{
    /**
     * List Poker rounds
     */
    public function index(Request $request)
    {
        $query = PokerRound::with(['game', 'round']);

        if ($request->has('game_id')) {
            $query->where('game_id', $request->get('game_id'));
        }

        if ($request->has('status')) {
            $query->where('status', $request->get('status'));
        }

        $perPage = $request->get('per_page', 15);
        $rounds = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'data' => $rounds->items(),
            'meta' => [
                'current_page' => $rounds->currentPage(),
                'last_page' => $rounds->lastPage(),
                'per_page' => $rounds->perPage(),
                'total' => $rounds->total(),
            ],
        ]);
    }

    /**
     * Create new Poker round
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'game_id' => 'required|exists:games,id',
            'duration_sec' => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $game = \App\Models\Game::where('id', $request->game_id)
            ->where('engine_key', 'poker')
            ->first();

        if (!$game) {
            return response()->json(['message' => 'Poker game not found'], 404);
        }

        $engine = new PokerGameEngine($game);
        $round = $engine->startNewRound($request->get('duration_sec', 120));

        return response()->json([
            'message' => 'Poker round created',
            'data' => $round->load('game', 'round'),
        ], 201);
    }

    /**
     * Show Poker round
     */
    public function show(string $id)
    {
        $round = PokerRound::with(['game', 'round', 'round.bets'])->find($id);

        if (!$round) {
            return response()->json(['message' => 'Round not found'], 404);
        }

        return response()->json(['data' => $round]);
    }

    /**
     * Update Poker round
     */
    public function update(Request $request, string $id)
    {
        $round = PokerRound::find($id);

        if (!$round) {
            return response()->json(['message' => 'Round not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'nullable|in:waiting,pre_flop,flop,turn,river,showdown,settling,settled,cancelled',
            'duration_sec' => 'nullable|integer|min:1',
            'metadata' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $round->update($validator->validated());

        return response()->json([
            'message' => 'Round updated',
            'data' => $round->fresh()->load('game', 'round'),
        ]);
    }

    /**
     * Delete Poker round
     */
    public function destroy(string $id)
    {
        $round = PokerRound::find($id);

        if (!$round) {
            return response()->json(['message' => 'Round not found'], 404);
        }

        $round->delete();

        return response()->json(['message' => 'Round deleted']);
    }

    /**
     * Deal Flop
     */
    public function dealFlop(string $id)
    {
        $round = PokerRound::with('game')->find($id);

        if (!$round) {
            return response()->json(['message' => 'Round not found'], 404);
        }

        $engine = new PokerGameEngine($round->game);
        $engine->dealFlop($round);

        return response()->json([
            'message' => 'Flop dealt',
            'data' => $round->fresh()->load('game', 'round'),
        ]);
    }

    /**
     * Deal Turn
     */
    public function dealTurn(string $id)
    {
        $round = PokerRound::with('game')->find($id);

        if (!$round) {
            return response()->json(['message' => 'Round not found'], 404);
        }

        $engine = new PokerGameEngine($round->game);
        $engine->dealTurn($round);

        return response()->json([
            'message' => 'Turn dealt',
            'data' => $round->fresh()->load('game', 'round'),
        ]);
    }

    /**
     * Deal River
     */
    public function dealRiver(string $id)
    {
        $round = PokerRound::with('game')->find($id);

        if (!$round) {
            return response()->json(['message' => 'Round not found'], 404);
        }

        $engine = new PokerGameEngine($round->game);
        $engine->dealRiver($round);

        return response()->json([
            'message' => 'River dealt',
            'data' => $round->fresh()->load('game', 'round'),
        ]);
    }

    /**
     * Evaluate Showdown
     */
    public function evaluateShowdown(string $id)
    {
        $round = PokerRound::with('game')->find($id);

        if (!$round) {
            return response()->json(['message' => 'Round not found'], 404);
        }

        $engine = new PokerGameEngine($round->game);
        $engine->evaluateShowdown($round);

        return response()->json([
            'message' => 'Showdown evaluated',
            'data' => $round->fresh()->load('game', 'round'),
        ]);
    }

    /**
     * Settle round
     */
    public function settle(string $id)
    {
        $round = PokerRound::with('game')->find($id);

        if (!$round) {
            return response()->json(['message' => 'Round not found'], 404);
        }

        $engine = new PokerGameEngine($round->game);
        $result = $engine->settleRound($round);

        return response()->json([
            'message' => 'Round settled',
            'data' => $result,
        ]);
    }

    /**
     * Cancel round
     */
    public function cancel(string $id)
    {
        $round = PokerRound::with('game')->find($id);

        if (!$round) {
            return response()->json(['message' => 'Round not found'], 404);
        }

        $engine = new PokerGameEngine($round->game);
        $result = $engine->cancelRound($round);

        return response()->json([
            'message' => 'Round cancelled',
            'data' => $result,
        ]);
    }
}
