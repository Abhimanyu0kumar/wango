<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LuckyDrawRound;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;

class LuckyDrawRoundController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = LuckyDrawRound::with(['game', 'round', 'modifiedBy']);

        if ($request->has('search')) {
            $search = $request->get('search');
            $query->whereHas('round', function ($q) use ($search) {
                $q->where('round_code', 'like', "%{$search}%");
            });
        }

        if ($request->has('game_id')) {
            $query->where('game_id', $request->get('game_id'));
        }

        if ($request->has('round_id')) {
            $query->where('round_id', $request->get('round_id'));
        }

        if ($request->has('status')) {
            $query->where('status', $request->get('status'));
        }

        if ($request->has('result_mode')) {
            $query->where('result_mode', $request->get('result_mode'));
        }

        if ($request->has('winning_side')) {
            $query->where('winning_side', $request->get('winning_side'));
        }

        if ($request->has('betting_from')) {
            $query->where('betting_starts_at', '>=', $request->get('betting_from'));
        }

        if ($request->has('betting_to')) {
            $query->where('betting_closes_at', '<=', $request->get('betting_to'));
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
            'round_id' => 'required|exists:game_rounds,id|unique:lucky_draw_rounds,round_id',
            'duration' => 'required|integer|min:1',
            'betting_starts_at' => 'nullable|date',
            'betting_closes_at' => 'nullable|date|after_or_equal:betting_starts_at',
            'result_at' => 'nullable|date',
            'small_wining_factor' => 'nullable|numeric|min:0',
            'draw_wining_factor' => 'nullable|numeric|min:0',
            'big_wining_factor' => 'nullable|numeric|min:0',
            'dice_one' => 'nullable|integer|min:1|max:6',
            'dice_two' => 'nullable|integer|min:1|max:6',
            'total' => 'nullable|integer|min:2|max:12',
            'winning_side' => 'nullable|in:small,big,draw',
            'result_mode' => 'nullable|in:automatic,manual',
            'status' => 'nullable|in:waiting,betting_open,locked,settling,settled,cancelled',
            'metadata' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        $round = LuckyDrawRound::create($data);
        $round->load(['game', 'round', 'modifiedBy']);

        return response()->json([
            'message' => 'Lucky draw round created successfully',
            'data' => $round
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $round = LuckyDrawRound::with(['game', 'round', 'modifiedBy'])->find($id);

        if (!$round) {
            return response()->json(['message' => 'Lucky draw round not found'], 404);
        }

        return response()->json(['data' => $round]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $round = LuckyDrawRound::find($id);

        if (!$round) {
            return response()->json(['message' => 'Lucky draw round not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'game_id' => 'nullable|exists:games,id',
            'round_id' => 'nullable|exists:game_rounds,id|unique:lucky_draw_rounds,round_id,' . $id,
            'duration' => 'nullable|integer|min:1',
            'betting_starts_at' => 'nullable|date',
            'betting_closes_at' => 'nullable|date|after_or_equal:betting_starts_at',
            'result_at' => 'nullable|date',
            'small_wining_factor' => 'nullable|numeric|min:0',
            'draw_wining_factor' => 'nullable|numeric|min:0',
            'big_wining_factor' => 'nullable|numeric|min:0',
            'dice_one' => 'nullable|integer|min:1|max:6',
            'dice_two' => 'nullable|integer|min:1|max:6',
            'total' => 'nullable|integer|min:2|max:12',
            'winning_side' => 'nullable|in:small,big,draw',
            'result_mode' => 'nullable|in:automatic,manual',
            'status' => 'nullable|in:waiting,betting_open,locked,settling,settled,cancelled',
            'metadata' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        $round->update($data);
        $round->load(['game', 'round', 'modifiedBy']);

        return response()->json([
            'message' => 'Lucky draw round updated successfully',
            'data' => $round
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $round = LuckyDrawRound::find($id);

        if (!$round) {
            return response()->json(['message' => 'Lucky draw round not found'], 404);
        }

        $round->delete();

        return response()->json(['message' => 'Lucky draw round deleted successfully']);
    }

    /**
     * Update round status.
     */
    public function updateStatus(Request $request, string $id)
    {
        $round = LuckyDrawRound::find($id);

        if (!$round) {
            return response()->json(['message' => 'Lucky draw round not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'required|in:waiting,betting_open,locked,settling,settled,cancelled',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $round->status = $validator->validated()['status'];
        $round->save();

        return response()->json([
            'message' => 'Round status updated successfully',
            'status' => $round->status
        ]);
    }

    /**
     * Set manual result for a round.
     */
    public function setResult(Request $request, string $id)
    {
        $round = LuckyDrawRound::find($id);

        if (!$round) {
            return response()->json(['message' => 'Lucky draw round not found'], 404);
        }

        if ($round->status === 'settled') {
            return response()->json(['message' => 'Cannot modify result of settled round'], 422);
        }

        $validator = Validator::make($request->all(), [
            'dice_one' => 'required|integer|min:1|max:6',
            'dice_two' => 'required|integer|min:1|max:6',
            'winning_side' => 'required|in:small,big,draw',
            'total_payout_amount' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $admin = Auth::guard('admin')->user();

        $round->dice_one = $data['dice_one'];
        $round->dice_two = $data['dice_two'];
        $round->total = $data['dice_one'] + $data['dice_two'];
        $round->winning_side = $data['winning_side'];
        $round->result_mode = 'manual';
        $round->modified_by = $admin?->id;
        $round->modified_at = now();
        $round->result_at = now();
        $round->status = 'settled';

        if (isset($data['total_payout_amount'])) {
            $round->total_payout_amount = $data['total_payout_amount'];
        }

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
        $round = LuckyDrawRound::find($id);

        if (!$round) {
            return response()->json(['message' => 'Lucky draw round not found'], 404);
        }

        if ($round->status === 'settled') {
            return response()->json(['message' => 'Cannot cancel a settled round'], 422);
        }

        $round->status = 'cancelled';
        $round->save();

        return response()->json([
            'message' => 'Round cancelled successfully',
            'data' => $round
        ]);
    }

    /**
     * Toggle duration active status for a game
     * POST /admin/v1/lucky-draw/{gameId}/toggle-duration
     */
    public function toggleDuration(Request $request, string $gameId)
    {
        $validator = Validator::make($request->all(), [
            'duration' => 'required|integer|min:5|max:300',
            'active' => 'required|boolean',
            'multipliers' => 'nullable|array',
            'multipliers.small' => 'nullable|numeric|min:1',
            'multipliers.draw' => 'nullable|numeric|min:1',
            'multipliers.big' => 'nullable|numeric|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $game = \App\Models\Game::find($gameId);
        if (!$game) {
            return response()->json(['message' => 'Game not found'], 404);
        }

        $data = $validator->validated();
        $metadata = $game->metadata ?? [];
        $durations = $metadata['durations'] ?? [];

        // Find existing duration config or create new
        $durationIndex = array_search($data['duration'], array_column($durations, 'duration'));

        if ($durationIndex !== false) {
            // Update existing
            $durations[$durationIndex]['active'] = $data['active'];
            if (isset($data['multipliers'])) {
                $durations[$durationIndex]['multipliers'] = $data['multipliers'];
            }
        } else {
            // Add new duration
            $durations[] = [
                'duration' => $data['duration'],
                'active' => $data['active'],
                'multipliers' => $data['multipliers'] ?? [
                    'small' => 1.9,
                    'draw' => 4.5,
                    'big' => 1.9,
                ],
            ];
        }

        $metadata['durations'] = $durations;
        $game->metadata = $metadata;
        $game->save();

        return response()->json([
            'message' => 'Duration updated successfully',
            'data' => [
                'game_id' => $game->id,
                'durations' => $durations,
            ]
        ]);
    }

    /**
     * Get game durations configuration
     * GET /admin/v1/lucky-draw/{gameId}/durations
     */
    public function getDurations(string $gameId)
    {
        $game = \App\Models\Game::find($gameId);
        if (!$game) {
            return response()->json(['message' => 'Game not found'], 404);
        }

        $metadata = $game->metadata ?? [];
        $durations = $metadata['durations'] ?? [
            ['duration' => 10, 'active' => true, 'multipliers' => ['small' => 1.9, 'draw' => 4.5, 'big' => 1.9]],
            ['duration' => 20, 'active' => true, 'multipliers' => ['small' => 1.9, 'draw' => 4.5, 'big' => 1.9]],
            ['duration' => 30, 'active' => true, 'multipliers' => ['small' => 1.9, 'draw' => 4.5, 'big' => 1.9]],
        ];

        return response()->json([
            'data' => [
                'game_id' => $game->id,
                'game_name' => $game->name,
                'durations' => $durations,
            ]
        ]);
    }
}
