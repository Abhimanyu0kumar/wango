<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class BetController extends Controller
{
    /**
     * List authenticated user's bet history
     * GET /user/v1/bets
     */
    public function index(Request $request)
    {
        $user = auth('api')->user();

        $query = $user->bets()->with(['game', 'round', 'settlement']);

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->get('status'));
        }

        // Filter by game
        if ($request->has('game_id')) {
            $query->where('game_id', $request->get('game_id'));
        }

        // Date range filter
        if ($request->has('from_date')) {
            $query->whereDate('placed_at', '>=', $request->get('from_date'));
        }
        if ($request->has('to_date')) {
            $query->whereDate('placed_at', '<=', $request->get('to_date'));
        }

        $perPage = $request->get('per_page', 15);
        $bets = $query->orderByDesc('placed_at')->paginate($perPage);

        return response()->json([
            'data' => $bets->items(),
            'meta' => [
                'current_page' => $bets->currentPage(),
                'last_page' => $bets->lastPage(),
                'per_page' => $bets->perPage(),
                'total' => $bets->total(),
            ]
        ]);
    }

    /**
     * Show bet detail
     * GET /user/v1/bets/{id}
     */
    public function show(string $id)
    {
        $user = auth('api')->user();

        $bet = $user->bets()->with(['game', 'round', 'settlement', 'wallet'])->find($id);

        if (!$bet) {
            return response()->json(['message' => 'Bet not found'], 404);
        }

        return response()->json([
            'data' => $bet
        ]);
    }
}
