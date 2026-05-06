<?php

namespace App\Jobs;

use App\Models\Game;
use App\Services\GameEngines\EngineRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * This job runs the game engine in a loop.
 * It will be dispatched when game is activated and runs for 1 hour before exiting.
 * The scheduler will restart it if needed.
 */
class RunGameEngineJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $gameId;
    public $timeout = 3600; // 1 hour max
    public $tries = 1;

    public function __construct(int $gameId)
    {
        $this->gameId = $gameId;
    }

    public function uniqueId(): string
    {
        return "game_engine_job_{$this->gameId}";
    }

    public function handle(): void
    {
        Log::info("Starting game engine job for game {$this->gameId}");

        // Check if game exists
        $game = Game::find($this->gameId);
        if (!$game) {
            Log::error("Game not found for engine job", ['game_id' => $this->gameId]);
            return;
        }

        // Check if game is active
        if ($game->status !== 'active') {
            Log::info("Game is not active, skipping engine run", [
                'game_id' => $this->gameId,
                'status' => $game->status
            ]);
            return;
        }

        try {
            $runner = new EngineRunner($this->gameId);
            $runner->sleepSeconds = 2;
            $runner->run();
        } catch (\Exception $e) {
            Log::error("Game engine job failed for game {$this->gameId}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }

        Log::info("Game engine job completed for game {$this->gameId}");
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("Game engine job failed permanently for game {$this->gameId}", [
            'error' => $exception->getMessage()
        ]);

        // The job will be retried by the scheduler
    }
}
