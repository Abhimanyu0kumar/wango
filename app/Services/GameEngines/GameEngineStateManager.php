<?php

namespace App\Services\GameEngines;

use App\Models\Game;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

/**
 * Manages game engine lifecycle via Redis.
 *
 * Each game engine has a Redis key: game_engine:{game_id}
 * State values: running, paused, stopping, stopped
 * Uses distributed locks to prevent race conditions.
 */
class GameEngineStateManager
{
    private const KEY_PREFIX = 'game_engine:';
    private const LOCK_PREFIX = 'game_engine_lock:';
    private const LOCK_TTL = 30; // seconds
    private const HEARTBEAT_TTL = 60; // seconds

    /**
     * Get engine state for a game.
     */
    public function getState(int $gameId): string
    {
        $key = $this->getStateKey($gameId);
        $state = Redis::get($key);
        return $state ?: 'stopped';
    }

    /**
     * Start an engine.
     */
    public function start(int $gameId): bool
    {
        return $this->withLock($gameId, function () use ($gameId) {
            $currentState = $this->getState($gameId);

            if ($currentState === 'running') {
                return false;
            }

            Redis::setex($this->getStateKey($gameId), 3600 * 24 * 30, 'running');
            Redis::setex($this->getHeartbeatKey($gameId), self::HEARTBEAT_TTL, now()->toIso8601String());

            Log::info("Game engine started", ['game_id' => $gameId]);
            return true;
        });
    }

    /**
     * Stop an engine gracefully.
     * Sets state to 'stopping' so the loop finishes current round then exits.
     */
    public function stop(int $gameId): bool
    {
        return $this->withLock($gameId, function () use ($gameId) {
            $currentState = $this->getState($gameId);

            if ($currentState === 'stopped') {
                return false;
            }

            Redis::setex($this->getStateKey($gameId), 3600 * 24 * 30, 'stopping');

            Log::info("Game engine stopping (graceful)", ['game_id' => $gameId]);
            return true;
        });
    }

    /**
     * Pause an engine (finish current round, then pause instead of starting new one).
     */
    public function pause(int $gameId): bool
    {
        return $this->withLock($gameId, function () use ($gameId) {
            $currentState = $this->getState($gameId);

            if ($currentState !== 'running') {
                return false;
            }

            Redis::setex($this->getStateKey($gameId), 3600 * 24 * 30, 'pausing');

            Log::info("Game engine pausing", ['game_id' => $gameId]);
            return true;
        });
    }

    /**
     * Resume a paused engine.
     */
    public function resume(int $gameId): bool
    {
        return $this->withLock($gameId, function () use ($gameId) {
            $currentState = $this->getState($gameId);

            if ($currentState !== 'paused') {
                return false;
            }

            Redis::setex($this->getStateKey($gameId), 3600 * 24 * 30, 'running');
            Redis::setex($this->getHeartbeatKey($gameId), self::HEARTBEAT_TTL, now()->toIso8601String());

            Log::info("Game engine resumed", ['game_id' => $gameId]);
            return true;
        });
    }

    /**
     * Mark engine as paused (called by loop after finishing current round during pause).
     */
    public function markPaused(int $gameId): void
    {
        Redis::setex($this->getStateKey($gameId), 3600 * 24 * 30, 'paused');
        Log::info("Game engine marked as paused", ['game_id' => $gameId]);
    }

    /**
     * Force stop an engine (no graceful shutdown).
     */
    public function forceStop(int $gameId): bool
    {
        return $this->withLock($gameId, function () use ($gameId) {
            Redis::setex($this->getStateKey($gameId), 3600 * 24 * 30, 'stopped');
            Redis::del($this->getHeartbeatKey($gameId));
            Redis::del($this->getPidKey($gameId));

            Log::warning("Game engine force stopped", ['game_id' => $gameId]);
            return true;
        });
    }

    /**
     * Update heartbeat (called by loop each iteration).
     */
    public function heartbeat(int $gameId): void
    {
        Redis::setex($this->getHeartbeatKey($gameId), self::HEARTBEAT_TTL, now()->toIso8601String());
    }

    /**
     * Register PID of the engine process.
     */
    public function registerPid(int $gameId, int $pid): void
    {
        Redis::setex($this->getPidKey($gameId), self::HEARTBEAT_TTL * 2, (string) $pid);
    }

    /**
     * Get engine info (state, heartbeat, pid).
     */
    public function getEngineInfo(int $gameId): array
    {
        return [
            'game_id' => $gameId,
            'state' => $this->getState($gameId),
            'heartbeat' => Redis::get($this->getHeartbeatKey($gameId)),
            'pid' => Redis::get($this->getPidKey($gameId)),
            'is_alive' => $this->isAlive($gameId),
        ];
    }

    /**
     * Check if engine is alive (has recent heartbeat).
     */
    public function isAlive(int $gameId): bool
    {
        $heartbeat = Redis::get($this->getHeartbeatKey($gameId));
        if (!$heartbeat) {
            return false;
        }

        $lastHeartbeat = \Illuminate\Support\Carbon::parse($heartbeat);
        return $lastHeartbeat->diffInSeconds(now()) < self::HEARTBEAT_TTL;
    }

    /**
     * Check if engine should continue running.
     * Returns false when state is 'stopping', 'paused', or 'stopped'.
     */
    public function shouldContinue(int $gameId): bool
    {
        return $this->getState($gameId) === 'running';
    }

    /**
     * Check if engine should finish current round then stop.
     */
    public function shouldFinishAndStop(int $gameId): bool
    {
        return $this->getState($gameId) === 'stopping';
    }

    /**
     * Check if engine should finish current round then pause.
     */
    public function shouldFinishAndPause(int $gameId): bool
    {
        return $this->getState($gameId) === 'pausing';
    }

    /**
     * Get all running engines.
     */
    public function getAllRunningEngines(): array
    {
        $runningEngines = [];
        $games = Game::whereIn('engine_key', ['dice', 'lucky_draw'])->get();

        foreach ($games as $game) {
            $state = $this->getState($game->id);
            if ($state === 'running' || $state === 'paused') {
                $runningEngines[] = $this->getEngineInfo($game->id);
            }
        }

        return $runningEngines;
    }

    /**
     * Get status of all games.
     */
    public function getAllEngineStatuses(): array
    {
        $statuses = [];
        $games = Game::whereIn('engine_key', ['dice', 'lucky_draw'])->get();

        foreach ($games as $game) {
            $info = $this->getEngineInfo($game->id);
            $info['game'] = [
                'id' => $game->id,
                'name' => $game->name,
                'engine_key' => $game->engine_key,
                'status' => $game->status,
            ];
            $statuses[] = $info;
        }

        return $statuses;
    }

    /**
     * Execute callback with distributed lock to prevent race conditions.
     */
    private function withLock(int $gameId, callable $callback): bool
    {
        $lockKey = self::LOCK_PREFIX . $gameId;
        $acquired = Redis::set($lockKey, '1', 'EX', self::LOCK_TTL, 'NX');

        if (!$acquired) {
            Log::warning("Could not acquire engine lock", ['game_id' => $gameId]);
            return false;
        }

        try {
            return $callback();
        } finally {
            Redis::del($lockKey);
        }
    }

    private function getStateKey(int $gameId): string
    {
        return self::KEY_PREFIX . $gameId . ':state';
    }

    private function getHeartbeatKey(int $gameId): string
    {
        return self::KEY_PREFIX . $gameId . ':heartbeat';
    }

    private function getPidKey(int $gameId): string
    {
        return self::KEY_PREFIX . $gameId . ':pid';
    }
}
