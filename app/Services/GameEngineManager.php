<?php

namespace App\Services;

use App\Jobs\RunGameEngineJob;
use App\Models\Game;
use App\Services\GameEngines\GameEngineStateManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

class GameEngineManager
{
    /**
     * Start the game engine for a specific game
     * Dispatches a queued job that runs the engine
     */
    public static function startEngine(int $gameId): bool
    {
        try {
            // Check if engine is already running
            if (self::isEngineRunning($gameId)) {
                Log::info("Engine already running for game {$gameId}");
                return true;
            }

            // Set initial state
            $stateManager = app(GameEngineStateManager::class);
            $stateManager->start($gameId);

            // Dispatch the engine job to the queue
            // This will run on the queue worker
            RunGameEngineJob::dispatch($gameId)->onQueue('game-engines');

            // Mark engine as scheduled in cache
            Cache::put("game_engine_scheduled_{$gameId}", true, 3600);

            Log::info("Dispatched game engine job for game {$gameId}");

            return true;
        } catch (\Exception $e) {
            Log::error("Failed to start game engine for game {$gameId}", [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Stop the game engine for a specific game
     */
    public static function stopEngine(int $gameId): bool
    {
        try {
            // Update game state to signal engine to stop
            $stateManager = app(GameEngineStateManager::class);
            $stateManager->stop($gameId);

            // Clear scheduled flag
            Cache::forget("game_engine_scheduled_{$gameId}");

            Log::info("Stopped game engine for game {$gameId}");
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to stop game engine for game {$gameId}", [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Check if engine is running for a game
     */
    public static function isEngineRunning(int $gameId): bool
    {
        // First check if job is scheduled
        if (!Cache::get("game_engine_scheduled_{$gameId}")) {
            return false;
        }

        // Check state manager for running state
        $stateManager = app(GameEngineStateManager::class);
        $state = $stateManager->getState($gameId);

        if ($state && in_array($state['state'] ?? 'idle', ['running', 'paused', 'starting'])) {
            // Check heartbeat - if older than 30 seconds, consider it dead
            $lastHeartbeat = $state['last_heartbeat'] ?? 0;
            if (time() - $lastHeartbeat > 30) {
                // Engine hasn't reported in 30 seconds
                Cache::forget("game_engine_scheduled_{$gameId}");
                return false;
            }
            return true;
        }

        return false;
    }

    /**
     * Restart a dead engine if game is still active
     */
    public static function restartIfNeeded(int $gameId): void
    {
        $game = Game::find($gameId);
        if (!$game) return;

        if ($game->status === 'active' && !self::isEngineRunning($gameId)) {
            Log::info("Restarting dead engine for game {$gameId}");
            self::startEngine($gameId);
        }
    }

    /**
     * Auto-start engines for all active games
     * Call this from scheduler every minute
     */
    public static function startEnginesForActiveGames(): void
    {
        $activeGames = Game::where('status', 'active')
            ->whereIn('engine_key', ['dice', 'lucky_draw'])
            ->get();

        foreach ($activeGames as $game) {
            // Check if engine is already running
            if (!self::isEngineRunning($game->id)) {
                self::startEngine($game->id);
                Log::info("Auto-started engine for game {$game->id}");
            }
        }

        Log::info("Engine check completed. Active games: " . $activeGames->count());
    }
}
