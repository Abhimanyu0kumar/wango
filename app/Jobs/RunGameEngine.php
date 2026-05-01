<?php

namespace App\Jobs;

use App\Services\GameEngines\EngineRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Runs a game engine in a persistent loop.
 * 
 * IMPORTANT: This is NOT a typical job that runs once and finishes.
 * It runs continuously until the engine state is changed to 'stopping' or 'stopped'.
 * 
 * For production: use a Supervisor-managed CLI process instead:
 *   php artisan game:engine {gameId}
 * 
 * This job is a fallback for environments without Supervisor.
 */
class RunGameEngine implements ShouldQueue
{
    use Queueable;

    /**
     * Allow this job to run for up to 1 hour before timeout.
     * Supervisor will restart it if killed.
     */
    public $timeout = 3600;

    /**
     * Don't retry on failure — Supervisor will restart the process.
     */
    public $tries = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $gameId,
    ) {
    }

    /**
     * Unique ID for this job (one engine per game).
     */
    public function uniqueId(): string
    {
        return "game_engine_{$this->gameId}";
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $runner = new EngineRunner($this->gameId);

        // Handle graceful shutdown signals
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, function () use ($runner) {
                $runner->stop();
            });
            pcntl_signal(SIGINT, function () use ($runner) {
                $runner->stop();
            });
        }

        $runner->run();
    }

    /**
     * Handle job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("Game engine job failed", [
            'game_id' => $this->gameId,
            'error' => $exception->getMessage(),
        ]);
    }
}
