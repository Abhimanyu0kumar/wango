<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GameRound extends Model
{
    protected $fillable = [
        'game_id',
        'round_code',
        'state',
        'starts_at',
        'betting_closes_at',
        'ended_at',
        'total_bet_amount',
        'total_payout_amount',
        'result',
        'metadata',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'betting_closes_at' => 'datetime',
        'ended_at' => 'datetime',
        'total_bet_amount' => 'decimal:2',
        'total_payout_amount' => 'decimal:2',
        'result' => 'array',
        'metadata' => 'array',
    ];

    public function game()
    {
        return $this->belongsTo(Game::class);
    }

    public function bets()
    {
        return $this->hasMany(Bet::class, 'round_id');
    }
}
