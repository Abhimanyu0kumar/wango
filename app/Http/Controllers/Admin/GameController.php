<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Game;
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
    public function show(string $id)
    {
        $game = Game::with(['category', 'rounds', 'bets'])->find($id);

        if (!$game) {
            return response()->json(['message' => 'Game not found'], 404);
        }

        return response()->json(['data' => $game]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $game = Game::find($id);

        if (!$game) {
            return response()->json(['message' => 'Game not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'category_id' => 'nullable|exists:categories,id',
            'name' => 'string|max:150',
            'slug' => 'nullable|string|max:150|unique:games,slug,' . $id,
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
    public function destroy(string $id)
    {
        $game = Game::find($id);

        if (!$game) {
            return response()->json(['message' => 'Game not found'], 404);
        }

        $game->delete();

        return response()->json(['message' => 'Game deleted successfully']);
    }

    /**
     * Toggle game status.
     */
    public function toggleStatus(string $id)
    {
        $game = Game::find($id);

        if (!$game) {
            return response()->json(['message' => 'Game not found'], 404);
        }

        $statuses = ['active', 'inactive', 'maintenance'];
        $currentIndex = array_search($game->status, $statuses);
        $nextIndex = ($currentIndex + 1) % count($statuses);
        $game->status = $statuses[$nextIndex];
        $game->save();

        return response()->json([
            'message' => 'Game status updated successfully',
            'status' => $game->status
        ]);
    }

    /**
     * Set game status to maintenance.
     */
    public function setMaintenance(string $id)
    {
        $game = Game::find($id);

        if (!$game) {
            return response()->json(['message' => 'Game not found'], 404);
        }

        $game->status = 'maintenance';
        $game->save();

        return response()->json([
            'message' => 'Game set to maintenance mode',
            'status' => $game->status
        ]);
    }
}
