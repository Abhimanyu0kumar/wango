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
}
