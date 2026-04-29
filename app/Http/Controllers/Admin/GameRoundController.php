<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GameRound;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class GameRoundController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = GameRound::with('game');

        if ($request->has('search')) {
            $search = $request->get('search');
            $query->where(function ($q) use ($search) {
                $q->where('round_code', 'like', "%{$search}%");
            });
        }

        if ($request->has('game_id')) {
            $query->where('game_id', $request->get('game_id'));
        }

        if ($request->has('state')) {
            $query->where('state', $request->get('state'));
        }

        if ($request->has('starts_from')) {
            $query->where('starts_at', '>=', $request->get('starts_from'));
        }

        if ($request->has('starts_to')) {
            $query->where('starts_at', '<=', $request->get('starts_to'));
        }

        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $perPage = $request->get('per_page', 15);
        $rounds = $query->paginate($perPage);

        return response()->json([
            'data' => $rounds->items(),
            'meta' => [
                'current_page' => $rounds->currentPage(),
                'last_page' => $rounds->lastPage(),
                'per_page' => $rounds->perPage(),
                'total' => $rounds->total(),
            ]
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'game_id' => 'required|exists:games,id',
            'round_code' => 'nullable|string|max:120|unique:game_rounds,round_code',
            'state' => 'nullable|in:waiting,betting_open,locked,running,settled,cancelled',
            'starts_at' => 'nullable|date',
            'betting_closes_at' => 'nullable|date|after_or_equal:starts_at',
            'ended_at' => 'nullable|date|after_or_equal:starts_at',
            'total_bet_amount' => 'nullable|numeric|min:0',
            'total_payout_amount' => 'nullable|numeric|min:0',
            'result' => 'nullable|array',
            'metadata' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        if (empty($data['round_code'])) {
            $data['round_code'] = 'RND-' . strtoupper(Str::random(8));
        }

        $round = GameRound::create($data);
        $round->load('game');

        return response()->json([
            'message' => 'Game round created successfully',
            'data' => $round
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $round = GameRound::with(['game', 'bets'])->find($id);

        if (!$round) {
            return response()->json(['message' => 'Game round not found'], 404);
        }

        return response()->json(['data' => $round]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $round = GameRound::find($id);

        if (!$round) {
            return response()->json(['message' => 'Game round not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'game_id' => 'nullable|exists:games,id',
            'round_code' => 'nullable|string|max:120|unique:game_rounds,round_code,' . $id,
            'state' => 'nullable|in:waiting,betting_open,locked,running,settled,cancelled',
            'starts_at' => 'nullable|date',
            'betting_closes_at' => 'nullable|date|after_or_equal:starts_at',
            'ended_at' => 'nullable|date|after_or_equal:starts_at',
            'total_bet_amount' => 'nullable|numeric|min:0',
            'total_payout_amount' => 'nullable|numeric|min:0',
            'result' => 'nullable|array',
            'metadata' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        $round->update($data);
        $round->load('game');

        return response()->json([
            'message' => 'Game round updated successfully',
            'data' => $round
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $round = GameRound::find($id);

        if (!$round) {
            return response()->json(['message' => 'Game round not found'], 404);
        }

        $round->delete();

        return response()->json(['message' => 'Game round deleted successfully']);
    }

    /**
     * Update round state.
     */
    public function updateState(Request $request, string $id)
    {
        $round = GameRound::find($id);

        if (!$round) {
            return response()->json(['message' => 'Game round not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'state' => 'required|in:waiting,betting_open,locked,running,settled,cancelled',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $round->state = $validator->validated()['state'];
        $round->save();

        return response()->json([
            'message' => 'Round state updated successfully',
            'state' => $round->state
        ]);
    }

    /**
     * Set round result.
     */
    public function setResult(Request $request, string $id)
    {
        $round = GameRound::find($id);

        if (!$round) {
            return response()->json(['message' => 'Game round not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'result' => 'required|array',
            'total_payout_amount' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $round->result = $data['result'];

        if (isset($data['total_payout_amount'])) {
            $round->total_payout_amount = $data['total_payout_amount'];
        }

        $round->state = 'settled';
        $round->ended_at = now();
        $round->save();

        return response()->json([
            'message' => 'Round result set successfully',
            'data' => $round
        ]);
    }

    /**
     * Cancel a round.
     */
    public function cancel(string $id)
    {
        $round = GameRound::find($id);

        if (!$round) {
            return response()->json(['message' => 'Game round not found'], 404);
        }

        if ($round->state === 'settled') {
            return response()->json(['message' => 'Cannot cancel a settled round'], 422);
        }

        $round->state = 'cancelled';
        $round->ended_at = now();
        $round->save();

        return response()->json([
            'message' => 'Round cancelled successfully',
            'data' => $round
        ]);
    }
}
