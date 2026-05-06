<?php

namespace App\Listeners;

use App\Events\GameStatusChanged;
use App\Jobs\CreateParallelLuckyDrawRounds;
use App\Services\GameEngineManager;
use Illuminate\Support\Facades\Log;

class HandleGameActivated
{
    public function handle(GameStatusChanged $event): void
    {
        // Only trigger when game becomes active from inactive
        if ($event->previousStatus === 'inactive' && $event->newStatus === 'active') {
            Log::info('Game activated, starting engine and creating rounds', [
                'game_id' => $event->game->id,
                'game_name' => $event->game->name,
                'engine_key' => $event->game->engine_key
            ]);

            // Dispatch job to create initial parallel rounds for all timers
            CreateParallelLuckyDrawRounds::dispatch($event->game->id);

            // Start the game engine for continuous round processing
            GameEngineManager::startEngine($event->game->id);

            Log::info('Engine started and round creation dispatched', [
                'game_id' => $event->game->id
            ]);
        }

        // Stop engine when game becomes inactive
        if ($event->previousStatus === 'active' && $event->newStatus === 'inactive') {
            Log::info('Game deactivated, stopping engine', [
                'game_id' => $event->game->id
            ]);

            GameEngineManager::stopEngine($event->game->id);
        }
    }
}
