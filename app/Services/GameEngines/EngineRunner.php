<?php

namespace App\Services\GameEngines;

use App\Models\Game;
use App\Models\LuckyDrawRound;
use App\Models\TeenPattiRound;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * Persistent engine runner.
 * 
 * This is NOT a queued job — it runs as a long-lived CLI process.
 * Use a Supervisor-managed worker: `php artisan game:engine {gameId}`
 * 
 * Architecture:
 * 1. Loop continuously, sleeping between iterations
 * 2. Check Redis state on each iteration (allows remote stop/pause)
 * 3. Use distributed locks when processing rounds (prevents race conditions)
 * 4. Heartbeat every iteration so state manager knows engine is alive
 * 5. Graceful shutdown: when state='stopping', finish current round then exit
 */
class EngineRunner
{
    private GameEngineStateManager $stateManager;
    private int $gameId;
    private bool $running = true;
    public int $sleepSeconds = 2; // Check interval (public for CLI command override)

    public function __construct(int $gameId)
    {
        $this->gameId = $gameId;
        $this->stateManager = app(GameEngineStateManager::class);
    }

    /**
     * Main engine loop.
     * Run via: php artisan game:engine {gameId}
     */
    public function run(): void
    {
        $game = Game::find($this->gameId);
        if (!$game) {
            Log::error("Game not found", ['game_id' => $this->gameId]);
            return;
        }

        $engine = $this->getEngineForGame($game);
        $pid = getmypid();

        $this->stateManager->registerPid($this->gameId, $pid);
        $this->stateManager->heartbeat($this->gameId);

        Log::info("Engine loop started", [
            'game_id' => $game->id,
            'game_name' => $game->name,
            'engine_key' => $game->engine_key,
            'pid' => $pid,
        ]);

        $currentRoundId = null;

        while ($this->running) {
            try {
                // Heartbeat every iteration
                $this->stateManager->heartbeat($this->gameId);

                // Check if should exit
                if ($this->stateManager->shouldFinishAndStop($this->gameId)) {
                    // If we have a current round, finish it then stop
                    if ($currentRoundId) {
                        $this->ensureRoundSettled($game, $engine);
                    }
                    Log::info("Engine stopping gracefully", ['game_id' => $this->gameId]);
                    $this->stateManager->forceStop($this->gameId);
                    break;
                }

                if ($this->stateManager->shouldFinishAndPause($this->gameId)) {
                    if ($currentRoundId) {
                        $this->ensureRoundSettled($game, $engine);
                    }
                    Log::info("Engine pausing", ['game_id' => $this->gameId]);
                    $this->stateManager->markPaused($this->gameId);
                    // Keep looping but don't process new rounds
                    sleep($this->sleepSeconds);
                    continue;
                }

                if (!$this->stateManager->shouldContinue($this->gameId)) {
                    // Not running and not pausing — exit
                    Log::info("Engine loop exiting (state changed)", ['game_id' => $this->gameId]);
                    break;
                }

                // Process game rounds
                $currentRoundId = $this->processGame($game, $engine);

                sleep($this->sleepSeconds);

            } catch (Exception $e) {
                Log::error("Engine loop error", [
                    'game_id' => $this->gameId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                sleep(5); // Back off on error
            }
        }

        Log::info("Engine loop ended", ['game_id' => $this->gameId]);
    }

    /**
     * Stop the engine loop (called via signal handler).
     */
    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * Process a single game's round lifecycle.
     * Returns the current round ID if there is one.
     */
    private function processGame(Game $game, object $engine): ?int
    {
        return match ($game->engine_key) {
            'dice' => $this->processLuckyDraw($game, $engine),
            'teenpatti' => $this->processTeenPatti($game, $engine),
            default => null,
        };
    }

    /**
     * Process Lucky Draw rounds.
     */
    private function processLuckyDraw(Game $game, LuckyDrawGameEngine $engine): ?int
    {
        $activeRound = LuckyDrawRound::where('game_id', $game->id)
            ->whereIn('status', ['betting_open', 'locked', 'settling'])
            ->with('round')
            ->first();

        if (!$activeRound) {
            // No active round — start one if game is active
            if ($game->status === 'active') {
                $duration = ($game->metadata['round_duration'] ?? 60);
                $engine->startNewRound($duration);
                Log::info("New Lucky Draw round started", ['game_id' => $game->id]);
            }
            return null;
        }

        $now = now();

        // Close betting when time expires
        if ($activeRound->status === 'betting_open' && $now->greaterThanOrEqualTo($activeRound->betting_closes_at)) {
            $engine->closeBetting($activeRound);
            Log::info("Betting closed", ['round_id' => $activeRound->id]);
            $activeRound->refresh();
        }

        // Generate result after locked delay
        if ($activeRound->status === 'locked') {
            $resultTime = $activeRound->betting_closes_at->copy()->addSeconds(3);
            if ($now->greaterThanOrEqualTo($resultTime)) {
                $engine->generateResult($activeRound);
                Log::info("Result generated", ['round_id' => $activeRound->id]);
                $activeRound->refresh();
            }
        }

        // Settle after result delay
        if ($activeRound->status === 'settling') {
            $settleTime = $activeRound->result_at?->copy()->addSeconds(5);
            if ($settleTime && $now->greaterThanOrEqualTo($settleTime)) {
                $engine->settleRound($activeRound);
                Log::info("Round settled", ['round_id' => $activeRound->id]);
            }
        }

        return $activeRound->id;
    }

    /**
     * Process Teen Patti rounds.
     */
    private function processTeenPatti(Game $game, TeenPattiGameEngine $engine): ?int
    {
        $activeRound = TeenPattiRound::where('game_id', $game->id)
            ->whereIn('status', ['betting_open', 'locked', 'settling'])
            ->with('round')
            ->first();

        if (!$activeRound) {
            if ($game->status === 'active') {
                $duration = ($game->metadata['round_duration'] ?? 60);
                $engine->startNewRound($duration);
                Log::info("New Teen Patti round started", ['game_id' => $game->id]);
            }
            return null;
        }

        $now = now();

        if ($activeRound->status === 'betting_open' && $now->greaterThanOrEqualTo($activeRound->betting_closes_at)) {
            $engine->closeBetting($activeRound);
            $activeRound->refresh();
        }

        if ($activeRound->status === 'locked') {
            $resultTime = $activeRound->betting_closes_at->copy()->addSeconds(3);
            if ($now->greaterThanOrEqualTo($resultTime)) {
                $engine->generateResult($activeRound);
                $activeRound->refresh();
            }
        }

        if ($activeRound->status === 'settling') {
            $settleTime = $activeRound->result_at?->copy()->addSeconds(5);
            if ($settleTime && $now->greaterThanOrEqualTo($settleTime)) {
                $engine->settleRound($activeRound);
            }
        }

        return $activeRound->id;
    }

    /**
     * Ensure the current round is settled before shutting down.
     */
    private function ensureRoundSettled(Game $game, object $engine): void
    {
        // Force any in-progress round to settle
        $activeRound = match ($game->engine_key) {
            'dice' => LuckyDrawRound::where('game_id', $game->id)
                ->whereIn('status', ['betting_open', 'locked', 'settling'])
                ->with('round')
                ->first(),
            'teenpatti' => TeenPattiRound::where('game_id', $game->id)
                ->whereIn('status', ['betting_open', 'locked', 'settling'])
                ->with('round')
                ->first(),
            default => null,
        };

        if (!$activeRound) {
            return;
        }

        Log::info("Ensuring round settled before shutdown", [
            'round_id' => $activeRound->id,
            'status' => $activeRound->status,
        ]);

        // Wait up to 30 seconds for round to settle
        for ($i = 0; $i < 15; $i++) {
            $activeRound->refresh();
            if ($activeRound->status === 'settled' || $activeRound->status === 'cancelled') {
                return;
            }

            // Force progression
            match (true) {
                $activeRound->status === 'betting_open' => $engine->closeBetting($activeRound),
                $activeRound->status === 'locked' => $engine->generateResult($activeRound),
                $activeRound->status === 'settling' => $engine->settleRound($activeRound),
                default => null,
            };

            $activeRound->refresh();
            sleep(2);
        }
    }

    /**
     * Get the appropriate game engine instance.
     */
    private function getEngineForGame(Game $game): object
    {
        return match ($game->engine_key) {
            'dice' => new LuckyDrawGameEngine($game),
            'teenpatti' => new TeenPattiGameEngine($game),
            default => throw new Exception("Unsupported engine key: {$game->engine_key}"),
        };
    }
}
