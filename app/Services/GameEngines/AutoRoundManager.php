<?php

namespace App\Services\GameEngines;

use App\Models\Game;
use App\Models\LuckyDrawRound;
use App\Models\GameRound;
use Illuminate\Support\Facades\Log;
use Exception;

class AutoRoundManager
{
    /**
     * Process all active games and manage their rounds
     */
    public function processAllGames(): array
    {
        $results = [
            'games_processed' => 0,
            'rounds_started' => 0,
            'rounds_locked' => 0,
            'rounds_resulted' => 0,
            'rounds_settled' => 0,
            'errors' => [],
        ];

        // Get all active Lucky Draw games
        $games = Game::where('engine_key', 'dice')
            ->where('status', 'active')
            ->get();

        foreach ($games as $game) {
            try {
                $results['games_processed']++;
                $gameResults = $this->processGame($game);
                
                $results['rounds_started'] += $gameResults['rounds_started'];
                $results['rounds_locked'] += $gameResults['rounds_locked'];
                $results['rounds_resulted'] += $gameResults['rounds_resulted'];
                $results['rounds_settled'] += $gameResults['rounds_settled'];
            } catch (Exception $e) {
                $results['errors'][] = "Game {$game->id}: " . $e->getMessage();
                Log::error("AutoRoundManager error for game {$game->id}: " . $e->getMessage());
            }
        }

        return $results;
    }

    /**
     * Process a single game
     */
    private function processGame(Game $game): array
    {
        return match ($game->engine_key) {
            'dice' => $this->processLuckyDrawGame($game),
            'teenpatti' => $this->processTeenPattiGame($game),
            'poker' => $this->processPokerGame($game),
            default => ['rounds_started' => 0, 'rounds_locked' => 0, 'rounds_resulted' => 0, 'rounds_settled' => 0],
        };
    }

    /**
     * Process Lucky Draw game
     */
    private function processLuckyDrawGame(Game $game): array
    {
        $engine = new LuckyDrawGameEngine($game);
        $results = [
            'rounds_started' => 0,
            'rounds_locked' => 0,
            'rounds_resulted' => 0,
            'rounds_settled' => 0,
        ];

        // Get timers from game metadata or use defaults
        $metadata = $game->metadata ?? [];
        $timersConfig = $metadata['timers'] ?? [
            ['duration_sec' => 10, 'status' => 'active'],
            ['duration_sec' => 20, 'status' => 'active'],
            ['duration_sec' => 30, 'status' => 'active'],
        ];

        // Filter active timers
        $activeTimers = array_filter($timersConfig, fn($t) => ($t['status'] ?? 'active') === 'active');
        $durations = array_column($activeTimers, 'duration_sec');

        if (empty($durations)) {
            $durations = [10, 20, 30]; // Fallback
        }

        // Process each duration independently
        foreach ($durations as $duration) {
            $activeRound = LuckyDrawRound::where('game_id', $game->id)
                ->where('duration_sec', $duration)
                ->whereIn('status', ['betting_open', 'locked', 'settling'])
                ->with('round')
                ->orderBy('created_at', 'desc')
                ->first();

            if (!$activeRound) {
                $engine->startNewRound($duration);
                $results['rounds_started']++;
                continue;
            }

            $now = now();

            // 1. Close betting (5s before end)
            if ($activeRound->status === 'betting_open' && $now->greaterThanOrEqualTo($activeRound->betting_closes_at)) {
                $engine->closeBetting($activeRound);
                $results['rounds_locked']++;
                $activeRound->refresh();
            }

            // 2. Generate result (1s before end)
            if ($activeRound->status === 'locked') {
                $resultTime = $activeRound->round->starts_at->copy()->addSeconds($duration - 1);
                if ($now->greaterThanOrEqualTo($resultTime)) {
                    $engine->generateResult($activeRound);
                    $results['rounds_resulted']++;
                    $activeRound->refresh();
                }
            }

            // 3. Settle round (immediately after result, before round ends)
            if ($activeRound->status === 'settling') {
                $engine->settleRound($activeRound);
                $results['rounds_settled']++;
                $activeRound->refresh();
            }

            // 4. Start next round if current is settled and time is up
            if ($activeRound->status === 'settled') {
                $endTime = $activeRound->round->starts_at->copy()->addSeconds($duration);
                if ($now->greaterThanOrEqualTo($endTime)) {
                    $engine->startNewRound($duration);
                    $results['rounds_started']++;
                }
            }
        }

        return $results;
    }

    /**
     * Process Teen Patti game
     */
    private function processTeenPattiGame(Game $game): array
    {
        $engine = new TeenPattiGameEngine($game);
        $results = [
            'rounds_started' => 0,
            'rounds_locked' => 0,
            'rounds_resulted' => 0,
            'rounds_settled' => 0,
        ];

        $activeRound = TeenPattiRound::where('game_id', $game->id)
            ->whereIn('status', ['betting_open', 'locked', 'settling'])
            ->with('round')
            ->first();

        if (!$activeRound) {
            $engine->startNewRound(60);
            $results['rounds_started']++;
            return $results;
        }

        $now = now();

        if ($activeRound->status === 'betting_open' && $now->greaterThanOrEqualTo($activeRound->betting_closes_at)) {
            $engine->closeBetting($activeRound);
            $results['rounds_locked']++;
            $activeRound->refresh();
        }

        if ($activeRound->status === 'locked') {
            $resultTime = $activeRound->betting_closes_at->copy()->addSeconds(3);
            if ($now->greaterThanOrEqualTo($resultTime)) {
                $engine->generateResult($activeRound);
                $results['rounds_resulted']++;
                $activeRound->refresh();
            }
        }

        if ($activeRound->status === 'settling') {
            $settleTime = $activeRound->result_at->copy()->addSeconds(5);
            if ($now->greaterThanOrEqualTo($settleTime)) {
                $engine->settleRound($activeRound);
                $results['rounds_settled']++;
            }
        }

        if ($activeRound->status === 'settled') {
            $engine->startNewRound(60);
            $results['rounds_started']++;
        }

        return $results;
    }

    /**
     * Process Poker game (placeholder)
     */
    private function processPokerGame(Game $game): array
    {
        return ['rounds_started' => 0, 'rounds_locked' => 0, 'rounds_resulted' => 0, 'rounds_settled' => 0];
    }

    /**
     * Get live state for a game (for real-time broadcasting)
     */
    public function getLiveState(Game $game): ?array
    {
        return match ($game->engine_key) {
            'dice' => $this->getLuckyDrawLiveState($game),
            'teenpatti' => $this->getTeenPattiLiveState($game),
            'poker' => $this->getPokerLiveState($game),
            default => null,
        };
    }

    private function getLuckyDrawLiveState(Game $game): ?array
    {
        $activeRound = LuckyDrawRound::where('game_id', $game->id)
            ->whereIn('status', ['betting_open', 'locked', 'settling', 'settled'])
            ->with(['round', 'round.bets'])
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$activeRound) {
            return null;
        }

        $totalBets = $activeRound->round->bets()->count();
        $totalBetAmount = $activeRound->round->bets()->sum('amount');

        return [
            'game_type' => 'dice',
            'round_id' => $activeRound->round_id,
            'round_code' => $activeRound->round->round_code,
            'status' => $activeRound->status,
            'betting_closes_at' => $activeRound->betting_closes_at?->toIso8601String(),
            'result_at' => $activeRound->result_at?->toIso8601String(),
            'dice_one' => $activeRound->dice_one,
            'dice_two' => $activeRound->dice_two,
            'total' => $activeRound->total,
            'winning_side' => $activeRound->winning_side,
            'total_bets' => $totalBets,
            'total_bet_amount' => round($totalBetAmount, 2),
            'small_multiplier' => $activeRound->small_multiplier,
            'draw_multiplier' => $activeRound->draw_multiplier,
            'big_multiplier' => $activeRound->big_multiplier,
        ];
    }

    private function getTeenPattiLiveState(Game $game): ?array
    {
        $activeRound = TeenPattiRound::where('game_id', $game->id)
            ->whereIn('status', ['betting_open', 'locked', 'settling', 'settled'])
            ->with(['round', 'round.bets'])
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$activeRound) {
            return null;
        }

        $totalBets = $activeRound->round->bets()->count();
        $totalBetAmount = $activeRound->round->bets()->sum('amount');

        return [
            'game_type' => 'teenpatti',
            'round_id' => $activeRound->round_id,
            'round_code' => $activeRound->round->round_code,
            'status' => $activeRound->status,
            'betting_closes_at' => $activeRound->betting_closes_at?->toIso8601String(),
            'result_at' => $activeRound->result_at?->toIso8601String(),
            'cards' => $activeRound->cards,
            'hand_type' => $activeRound->hand_type,
            'winning_bet_type' => $activeRound->winning_bet_type,
            'total_bets' => $totalBets,
            'total_bet_amount' => round($totalBetAmount, 2),
            'multipliers' => $activeRound->multipliers,
        ];
    }

    private function getPokerLiveState(Game $game): ?array
    {
        return null; // Placeholder
    }
}
