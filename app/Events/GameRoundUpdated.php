<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class GameRoundUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $round;
    public array $broadcastData;

    public function __construct($round)
    {
        $this->round = $round;
        $this->broadcastData = $this->prepareData();
    }

    public function broadcastOn(): array
    {
        $gameId = $this->round->game_id;
        return [
            new Channel('game.' . $gameId),
            new Channel('game.' . ($this->round->game->engine_key ?? 'unknown')),
        ];
    }

    public function broadcastAs(): string
    {
        return 'round.updated';
    }

    private function prepareData(): array
    {
        $round = $this->round;
        $gameType = $round->game->engine_key ?? 'unknown';
        
        $baseData = [
            'game_type' => $gameType,
            'round_id' => $round->round_id ?? $round->id,
            'status' => $round->status,
        ];

        // Get related game round
        $gameRound = $round->round ?? null;
        
        if ($gameRound) {
            $baseData['round_code'] = $gameRound->round_code;
            $baseData['betting_closes_at'] = $gameRound->betting_closes_at?->toIso8601String();
            $baseData['result_at'] = $gameRound->ended_at?->toIso8601String();
            $baseData['total_bets'] = $gameRound->bets()->count();
            $baseData['total_bet_amount'] = round($gameRound->bets()->sum('amount'), 2);
        }

        // Game-specific data (only Lucky Draw / Dice is supported)
        if ($gameType === 'dice' || $gameType === 'lucky_draw') {
            $baseData['dice_one'] = $round->dice_one;
            $baseData['dice_two'] = $round->dice_two;
            $baseData['total'] = $round->total;
            $baseData['winning_side'] = $round->winning_side;
            $baseData['small_multiplier'] = $round->small_multiplier;
            $baseData['draw_multiplier'] = $round->draw_multiplier;
            $baseData['big_multiplier'] = $round->big_multiplier;
        }

        return $baseData;
    }
}
