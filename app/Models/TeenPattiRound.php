<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TeenPattiRound extends Model
{
    use HasFactory;

    protected $table = 'teen_patti_rounds';

    protected $fillable = [
        'game_id',
        'round_id',
        'cards',
        'hand_type',
        'hand_rank',
        'winning_bet_type',
        'multipliers',
        'duration_sec',
        'betting_starts_at',
        'betting_closes_at',
        'result_at',
        'metadata',
        'status',
    ];

    protected $casts = [
        'cards' => 'array',
        'multipliers' => 'array',
        'metadata' => 'array',
        'betting_starts_at' => 'datetime',
        'betting_closes_at' => 'datetime',
        'result_at' => 'datetime',
    ];

    public function game()
    {
        return $this->belongsTo(Game::class);
    }

    public function round()
    {
        return $this->belongsTo(GameRound::class, 'round_id');
    }

    public function isBettingOpen(): bool
    {
        return $this->status === 'betting_open';
    }

    public function isSettled(): bool
    {
        return $this->status === 'settled';
    }
}
