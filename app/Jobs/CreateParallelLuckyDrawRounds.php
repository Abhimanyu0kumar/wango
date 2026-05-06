<?php

namespace App\Jobs;

use App\Models\Game;
use App\Services\GameEngines\LuckyDrawGameEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CreateParallelLuckyDrawRounds implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $gameId;

    public function __construct(int $gameId)
    {
        $this->gameId = $gameId;
    }

    public function handle(): void
    {
        $game = Game::find($this->gameId);

        if (!$game) {
            Log::error('Game not found for creating parallel rounds', ['game_id' => $this->gameId]);
            return;
        }

        if ($game->status !== 'active') {
            Log::info('Game is not active, skipping round creation', ['game_id' => $this->gameId, 'status' => $game->status]);
            return;
        }

        $metadata = $game->metadata ?? [];
        $timers = $metadata['timers'] ?? [];

        if (empty($timers)) {
            Log::info('No timers configured for game', ['game_id' => $this->gameId]);
            return;
        }

        $engine = new LuckyDrawGameEngine($game);

        foreach ($timers as $timer) {
            // Only create rounds for active timers
            if (($timer['status'] ?? 'active') !== 'active') {
                Log::info('Skipping inactive timer', [
                    'game_id' => $this->gameId,
                    'duration' => $timer['duration_sec'] ?? 'unknown'
                ]);
                continue;
            }

            $durationSec = $timer['duration_sec'] ?? 60;
            $lockTimeSec = $timer['lock_time_sec'] ?? 5;

            try {
                // Check if an active round already exists for this duration
                $existingRound = \App\Models\LuckyDrawRound::where('game_id', $game->id)
                    ->where('duration_sec', $durationSec)
                    ->whereIn('status', ['betting_open', 'locked', 'settling'])
                    ->first();

                if ($existingRound) {
                    Log::info('Active round already exists for timer', [
                        'game_id' => $game->id,
                        'duration' => $durationSec,
                        'round_id' => $existingRound->id
                    ]);
                    continue;
                }

                // Create new round for this timer duration
                $round = $engine->startNewRound($durationSec);

                Log::info('Created parallel lucky draw round', [
                    'game_id' => $game->id,
                    'round_id' => $round->id,
                    'duration' => $durationSec,
                    'lock_time' => $lockTimeSec,
                    'betting_closes_at' => $round->betting_closes_at,
                    'result_at' => $round->result_at
                ]);

            } catch (\Exception $e) {
                Log::error('Failed to create round for timer', [
                    'game_id' => $game->id,
                    'duration' => $durationSec,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }
}
