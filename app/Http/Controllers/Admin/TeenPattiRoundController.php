<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TeenPattiRound;
use App\Services\GameEngines\TeenPattiGameEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TeenPattiRoundController extends Controller
{
    /**
     * List Teen Patti rounds
     */
    public function index(Request $request)
    {
        $query = TeenPattiRound::with(['game', 'round']);

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
     * Create new Teen Patti round
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
            ->where('engine_key', 'teenpatti')
            ->first();

        if (!$game) {
            return response()->json(['message' => 'Teen Patti game not found'], 404);
        }

        $engine = new TeenPattiGameEngine($game);
        $round = $engine->startNewRound($request->get('duration_sec', 60));

        return response()->json([
            'message' => 'Teen Patti round created',
            'data' => $round->load('game', 'round'),
        ], 201);
    }

    /**
     * Show Teen Patti round
     */
    public function show(string $id)
    {
        $round = TeenPattiRound::with(['game', 'round', 'round.bets'])->find($id);

        if (!$round) {
            return response()->json(['message' => 'Round not found'], 404);
        }

        return response()->json(['data' => $round]);
    }

    /**
     * Update Teen Patti round
     */
    public function update(Request $request, string $id)
    {
        $round = TeenPattiRound::find($id);

        if (!$round) {
            return response()->json(['message' => 'Round not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'nullable|in:waiting,betting_open,locked,settling,settled,cancelled',
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
     * Delete Teen Patti round
     */
    public function destroy(string $id)
    {
        $round = TeenPattiRound::find($id);

        if (!$round) {
            return response()->json(['message' => 'Round not found'], 404);
        }

        $round->delete();

        return response()->json(['message' => 'Round deleted']);
    }

    /**
     * Update round status
     */
    public function updateStatus(Request $request, string $id)
    {
        $round = TeenPattiRound::find($id);

        if (!$round) {
            return response()->json(['message' => 'Round not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'required|in:waiting,betting_open,locked,settling,settled,cancelled',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $round->update(['status' => $request->status]);
        $round->round->update(['state' => $request->status]);

        return response()->json([
            'message' => 'Status updated',
            'status' => $round->status,
        ]);
    }

    /**
     * Set manual result
     */
    public function setResult(Request $request, string $id)
    {
        $round = TeenPattiRound::with('game')->find($id);

        if (!$round) {
            return response()->json(['message' => 'Round not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'cards' => 'required|array|size:3',
            'cards.*' => 'string|max:10',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $engine = new TeenPattiGameEngine($round->game);
        $engine->setManualResult($round, $request->cards);

        return response()->json([
            'message' => 'Result set successfully',
            'data' => $round->fresh()->load('game', 'round'),
        ]);
    }

    /**
     * Cancel round
     */
    public function cancel(string $id)
    {
        $round = TeenPattiRound::with('game')->find($id);

        if (!$round) {
            return response()->json(['message' => 'Round not found'], 404);
        }

        $engine = new TeenPattiGameEngine($round->game);
        $result = $engine->cancelRound($round);

        return response()->json([
            'message' => 'Round cancelled',
            'data' => $result,
        ]);
    }
}
