<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Services\GameEngines\GameEngineStateManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AdminEngineController extends Controller
{
    private GameEngineStateManager $stateManager;

    public function __construct(GameEngineStateManager $stateManager)
    {
        $this->stateManager = $stateManager;
    }

    /**
     * Get status of all game engines.
     * GET /admin/v1/engines
     */
    public function index()
    {
        $engines = $this->stateManager->getAllEngineStatuses();

        return response()->json([
            'data' => $engines,
            'meta' => [
                'total' => count($engines),
                'running' => count(array_filter($engines, fn($e) => $e['state'] === 'running')),
                'paused' => count(array_filter($engines, fn($e) => $e['state'] === 'paused')),
                'stopped' => count(array_filter($engines, fn($e) => $e['state'] === 'stopped')),
            ],
        ]);
    }

    /**
     * Get status of a specific game engine.
     * GET /admin/v1/engines/{gameId}
     */
    public function show(int $gameId)
    {
        $game = Game::findOrFail($gameId);
        $info = $this->stateManager->getEngineInfo($gameId);
        $info['game'] = [
            'id' => $game->id,
            'name' => $game->name,
            'engine_key' => $game->engine_key,
            'status' => $game->status,
        ];

        return response()->json(['data' => $info]);
    }

    /**
     * Start a game engine.
     * POST /admin/v1/engines/{gameId}/start
     */
    public function start(int $gameId)
    {
        $game = Game::findOrFail($gameId);

        if (!in_array($game->engine_key, ['dice', 'teenpatti', 'poker'])) {
            return response()->json([
                'message' => "Engine not supported for game type: {$game->engine_key}",
            ], 400);
        }

        $success = $this->stateManager->start($gameId);

        if (!$success) {
            return response()->json([
                'message' => 'Engine could not be started (may already be running or locked)',
            ], 409);
        }

        // Dispatch engine runner to queue for processing
        $this->dispatchEngineRunner($gameId);

        return response()->json([
            'message' => 'Engine starting',
            'data' => $this->stateManager->getEngineInfo($gameId),
        ]);
    }

    /**
     * Stop a game engine gracefully.
     * POST /admin/v1/engines/{gameId}/stop
     */
    public function stop(int $gameId)
    {
        $game = Game::findOrFail($gameId);
        $success = $this->stateManager->stop($gameId);

        if (!$success) {
            return response()->json([
                'message' => 'Engine could not be stopped (may already be stopped or locked)',
            ], 409);
        }

        return response()->json([
            'message' => 'Engine stopping gracefully (will finish current round)',
            'data' => $this->stateManager->getEngineInfo($gameId),
        ]);
    }

    /**
     * Pause a game engine.
     * POST /admin/v1/engines/{gameId}/pause
     */
    public function pause(int $gameId)
    {
        $game = Game::findOrFail($gameId);
        $success = $this->stateManager->pause($gameId);

        if (!$success) {
            return response()->json([
                'message' => 'Engine could not be paused (may not be running or locked)',
            ], 409);
        }

        return response()->json([
            'message' => 'Engine pausing (will finish current round then pause)',
            'data' => $this->stateManager->getEngineInfo($gameId),
        ]);
    }

    /**
     * Resume a paused engine.
     * POST /admin/v1/engines/{gameId}/resume
     */
    public function resume(int $gameId)
    {
        $game = Game::findOrFail($gameId);
        $success = $this->stateManager->resume($gameId);

        if (!$success) {
            return response()->json([
                'message' => 'Engine could not be resumed (may not be paused or locked)',
            ], 409);
        }

        $this->dispatchEngineRunner($gameId);

        return response()->json([
            'message' => 'Engine resuming',
            'data' => $this->stateManager->getEngineInfo($gameId),
        ]);
    }

    /**
     * Force stop an engine immediately.
     * POST /admin/v1/engines/{gameId}/force-stop
     */
    public function forceStop(int $gameId)
    {
        $game = Game::findOrFail($gameId);
        $success = $this->stateManager->forceStop($gameId);

        return response()->json([
            'message' => 'Engine force stopped',
            'data' => $this->stateManager->getEngineInfo($gameId),
        ]);
    }

    /**
     * Restart an engine (stop then start).
     * POST /admin/v1/engines/{gameId}/restart
     */
    public function restart(int $gameId)
    {
        $game = Game::findOrFail($gameId);
        
        // Force stop first
        $this->stateManager->forceStop($gameId);
        
        // Brief delay to ensure cleanup
        usleep(500000); // 500ms
        
        // Start again
        $success = $this->stateManager->start($gameId);

        if (!$success) {
            return response()->json([
                'message' => 'Engine could not be restarted (locked)',
            ], 409);
        }

        $this->dispatchEngineRunner($gameId);

        return response()->json([
            'message' => 'Engine restarted',
            'data' => $this->stateManager->getEngineInfo($gameId),
        ]);
    }

    /**
     * Dispatch the engine runner for a game.
     * If engine is running, it's assumed a worker is already processing it.
     * For production, use a supervisor-managed process instead of queuing.
     */
    private function dispatchEngineRunner(int $gameId): void
    {
        // In production: use supervisor to manage php artisan game:engine {gameId}
        // For now, dispatch via queue with a unique worker per game
        dispatch(new \App\Jobs\RunGameEngine($gameId))
            ->onQueue('game-engines')
            ->uniqueLock(3600);
    }
}
