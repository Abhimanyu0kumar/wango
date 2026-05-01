<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\GameEngines\AutoRoundManager;
use Illuminate\Support\Facades\Log;

class ProcessGameRounds extends Command
{
    protected $signature = 'game:process-rounds {--once : Run once instead of continuous}';
    protected $description = 'Process active game rounds (auto-start, lock betting, generate results, settle)';

    protected $autoRoundManager;

    public function __construct(AutoRoundManager $autoRoundManager)
    {
        parent::__construct();
        $this->autoRoundManager = $autoRoundManager;
    }

    public function handle(): int
    {
        $this->info('Processing game rounds...');
        
        try {
            $results = $this->autoRoundManager->processAllGames();
            
            $this->info('Games processed: ' . $results['games_processed']);
            $this->info('Rounds started: ' . $results['rounds_started']);
            $this->info('Rounds locked: ' . $results['rounds_locked']);
            $this->info('Rounds resulted: ' . $results['rounds_resulted']);
            $this->info('Rounds settled: ' . $results['rounds_settled']);
            
            if (!empty($results['errors'])) {
                $this->error('Errors encountered:');
                foreach ($results['errors'] as $error) {
                    $this->error($error);
                }
                Log::error('Game round processing errors', $results['errors']);
            }
            
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Failed to process game rounds: ' . $e->getMessage());
            Log::error('ProcessGameRounds command failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
