<?php

namespace App\Events;

use App\Models\Game;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class GameStatusChanged
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Game $game;
    public string $previousStatus;
    public string $newStatus;

    public function __construct(Game $game, string $previousStatus, string $newStatus)
    {
        $this->game = $game;
        $this->previousStatus = $previousStatus;
        $this->newStatus = $newStatus;
    }
}
