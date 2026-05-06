<?php

namespace App\Http\Controllers\Admin;

use App\Events\GameStatusChanged;
use App\Http\Controllers\Controller;
use App\Jobs\CreateParallelLuckyDrawRounds;
use App\Models\Game;
use App\Models\GameRound;
use App\Models\LuckyDrawRound;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class GameController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Game::with('category');

        if ($request->has('search')) {
            $search = $request->get('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('slug', 'like', "%{$search}%")
                  ->orWhere('engine_key', 'like', "%{$search}%")
                  ->orWhere('provider', 'like', "%{$search}%");
            });
        }

        if ($request->has('category_id')) {
            $query->where('category_id', $request->get('category_id'));
        }

        if ($request->has('status')) {
            $query->where('status', $request->get('status'));
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
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'category_id' => 'required|exists:categories,id',
            'name' => 'required|string|max:150',
            'slug' => 'nullable|string|max:150|unique:games,slug',
            'engine_key' => 'required|string|max:100',
            'provider' => 'nullable|string|max:100',
            'min_bet' => 'nullable|numeric|min:0',
            'max_bet' => 'nullable|numeric|min:0',
            'metadata' => 'nullable|array',
            'status' => 'nullable|in:active,inactive,maintenance',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        if (empty($data['slug'])) {
            $data['slug'] = Str::slug($data['name']);
        }

        $game = Game::create($data);
        $game->load('category');

        return response()->json([
            'message' => 'Game created successfully',
            'data' => $game
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Game $game)
    {
        return response()->json(['data' => $game->load(['category', 'rounds', 'bets'])]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Game $game)
    {
        $validator = Validator::make($request->all(), [
            'category_id' => 'nullable|exists:categories,id',
            'name' => 'string|max:150',
            'slug' => 'nullable|string|max:150|unique:games,slug,' . $game->id,
            'engine_key' => 'string|max:100',
            'provider' => 'nullable|string|max:100',
            'min_bet' => 'nullable|numeric|min:0',
            'max_bet' => 'nullable|numeric|min:0',
            'metadata' => 'nullable|array',
            'status' => 'nullable|in:active,inactive,maintenance',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        $game->update($data);
        $game->load('category');

        return response()->json([
            'message' => 'Game updated successfully',
            'data' => $game
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Game $game)
    {
        $game->delete();

        return response()->json(['message' => 'Game deleted successfully']);
    }

    /**
     * Toggle game status.
     */
    public function toggleStatus(Game $game)
    {
        $previousStatus = $game->status;

        // Simple toggle between active and inactive only
        $game->status = $previousStatus === 'active' ? 'inactive' : 'active';
        $game->save();

        // Dispatch event when status changes
        GameStatusChanged::dispatch($game, $previousStatus, $game->status);

        return response()->json([
            'message' => 'Game status updated successfully',
            'status' => $game->status
        ]);
    }

    /**
     * Set game status to maintenance.
     */
    public function setMaintenance(Game $game)
    {
        $game->status = 'maintenance';
        $game->save();

        return response()->json([
            'message' => 'Game set to maintenance mode',
            'status' => $game->status
        ]);
    }

    /**
     * Update a specific timer's settings in metadata.
     */
    public function updateTimer(Request $request, Game $game, $duration)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'nullable|in:active,inactive',
            'multipliers' => 'nullable|array',
            'multipliers.small' => 'nullable|numeric|min:1',
            'multipliers.draw' => 'nullable|numeric|min:1',
            'multipliers.big' => 'nullable|numeric|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $metadata = $game->metadata ?? [];
        $timers = $metadata['timers'] ?? [];
        $found = false;

        foreach ($timers as &$timer) {
            if ($timer['duration_sec'] == $duration) {
                $found = true;
                if ($request->has('status')) {
                    $timer['status'] = $request->get('status');
                }
                if ($request->has('multipliers')) {
                    $timer['multipliers'] = array_merge($timer['multipliers'] ?? [], $request->get('multipliers'));
                }
                break;
            }
        }

        if (!$found) {
            return response()->json(['message' => 'Timer not found'], 404);
        }

        $metadata['timers'] = $timers;
        $game->metadata = $metadata;
        $game->save();

        return response()->json([
            'message' => 'Timer updated successfully',
            'data' => $game->metadata
        ]);
    }

    /**
     * Get current active rounds for a game.
     */
    public function getRounds(Game $game)
    {
        $rounds = LuckyDrawRound::where('game_id', $game->id)
            ->with('round')
            ->orderBy('created_at', 'desc')
            ->take(20)
            ->get()
            ->map(function ($luckyDrawRound) {
                return [
                    'id' => $luckyDrawRound->id,
                    'round_id' => $luckyDrawRound->round_id,
                    'round_code' => $luckyDrawRound->round->round_code ?? null,
                    'duration_sec' => $luckyDrawRound->duration_sec,
                    'status' => $luckyDrawRound->status,
                    'betting_starts_at' => $luckyDrawRound->betting_starts_at,
                    'betting_closes_at' => $luckyDrawRound->betting_closes_at,
                    'result_at' => $luckyDrawRound->result_at,
                    'dice_one' => $luckyDrawRound->dice_one,
                    'dice_two' => $luckyDrawRound->dice_two,
                    'total' => $luckyDrawRound->total,
                    'winning_side' => $luckyDrawRound->winning_side,
                    'multipliers' => [
                        'small' => $luckyDrawRound->small_multiplier,
                        'draw' => $luckyDrawRound->draw_multiplier,
                        'big' => $luckyDrawRound->big_multiplier,
                    ],
                    'total_bets' => $luckyDrawRound->round->bets()->count(),
                    'total_bet_amount' => $luckyDrawRound->round->total_bet_amount,
                    'created_at' => $luckyDrawRound->created_at,
                ];
            });

        return response()->json([
            'data' => $rounds,
            'game' => [
                'id' => $game->id,
                'name' => $game->name,
                'status' => $game->status,
                'timers' => $game->metadata['timers'] ?? [],
            ]
        ]);
    }

    /**
     * Manually trigger round creation for a game.
     */
    public function createRounds(Game $game)
    {
        if ($game->status !== 'active') {
            return response()->json([
                'message' => 'Game must be active to create rounds',
                'status' => $game->status
            ], 422);
        }

        // Dispatch job to create rounds
        CreateParallelLuckyDrawRounds::dispatch($game->id);

        return response()->json([
            'message' => 'Round creation job dispatched',
            'game_id' => $game->id,
            'timers' => $game->metadata['timers'] ?? []
        ]);
    }
}
