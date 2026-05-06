<?php

namespace App\Console\Commands;

use App\Jobs\CreateParallelLuckyDrawRounds;
use App\Models\Game;
use Illuminate\Console\Command;

class CreateLuckyDrawRounds extends Command
{
    protected $signature = 'game:create-rounds 
                            {gameId? : The game ID (optional, defaults to lucky-draw game)}';

    protected $description = 'Create initial lucky draw rounds for all active timers';

    public function handle(): int
    {
        $gameId = $this->argument('gameId');

        // If no game ID provided, find lucky-draw game
        if (!$gameId) {
            $game = Game::where('slug', 'lucky-draw')
                ->orWhere('engine_key', 'dice')
                ->first();

            if (!$game) {
                $this->error('No lucky-draw game found. Please provide a game ID.');
                return Command::FAILURE;
            }

            $gameId = $game->id;
            $this->info("Found game: {$game->name} (ID: {$gameId})");
        } else {
            $game = Game::find($gameId);
            if (!$game) {
                $this->error("Game with ID {$gameId} not found.");
                return Command::FAILURE;
            }
        }

        if ($game->status !== 'active') {
            $this->warn("Game status is '{$game->status}'. Rounds will only be created for active games.");
            $this->info("Use admin panel to activate the game first.");
            return Command::FAILURE;
        }

        $this->info("Creating rounds for game: {$game->name}");

        $timers = $game->metadata['timers'] ?? [];
        if (empty($timers)) {
            $this->warn('No timers configured for this game.');
            return Command::FAILURE;
        }

        $this->info('Active timers:');
        foreach ($timers as $timer) {
            $status = $timer['status'] ?? 'active';
            $this->info("  - {$timer['duration_sec']}s: {$status}");
        }

        // Dispatch the job
        CreateParallelLuckyDrawRounds::dispatch($gameId);

        $this->info('Job dispatched. Rounds will be created shortly.');
        $this->info('');
        $this->info('To process the queue, run:');
        $this->info('  php artisan queue:work --queue=default');
        $this->info('');
        $this->info('To start the game engine (for continuous round creation):');
        $this->info('  php artisan game:engine ' . $gameId);

        return Command::SUCCESS;
    }
}
