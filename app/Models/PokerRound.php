<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PokerRound extends Model
{
    use HasFactory;

    protected $table = 'poker_rounds';

    protected $fillable = [
        'game_id',
        'round_id',
        'community_cards',
        'status',
        'duration_sec',
        'betting_starts_at',
        'betting_closes_at',
        'flop_at',
        'turn_at',
        'river_at',
        'showdown_at',
        'metadata',
    ];

    protected $casts = [
        'community_cards' => 'array',
        'metadata' => 'array',
        'betting_starts_at' => 'datetime',
        'betting_closes_at' => 'datetime',
        'flop_at' => 'datetime',
        'turn_at' => 'datetime',
        'river_at' => 'datetime',
        'showdown_at' => 'datetime',
    ];

    public function game()
    {
        return $this->belongsTo(Game::class);
    }

    public function round()
    {
        return $this->belongsTo(GameRound::class, 'round_id');
    }
}
