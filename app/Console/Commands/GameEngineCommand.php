<?php

namespace App\Console\Commands;

use App\Services\GameEngines\EngineRunner;
use Illuminate\Console\Command;

class GameEngineCommand extends Command
{
    protected $signature = 'game:engine 
                            {gameId : The game ID to run the engine for}
                            {--sleep=2 : Seconds between iterations}';

    protected $description = 'Run a persistent game engine loop for a specific game';

    public function handle(): int
    {
        $gameId = (int) $this->argument('gameId');
        $sleep = (int) $this->option('sleep');

        $this->info("Starting game engine for game #{$gameId} (sleep: {$sleep}s)...");

        $runner = new EngineRunner($gameId);
        $runner->sleepSeconds = $sleep;

        // Handle graceful shutdown signals
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, function () use ($runner) {
                $this->info('Received SIGTERM, stopping engine...');
                $runner->stop();
            });
            pcntl_signal(SIGINT, function () use ($runner) {
                $this->info('Received SIGINT, stopping engine...');
                $runner->stop();
            });
        }

        $runner->run();

        $this->info("Game engine #{$gameId} stopped.");
        return Command::SUCCESS;
    }
}
