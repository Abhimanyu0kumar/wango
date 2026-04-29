<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LuckyDrawRound extends Model
{
    use HasFactory;

    protected $table = 'lucky_draw_rounds';

    protected $fillable = [
        'game_id',
        'round_id',
        'duration_sec',
        'betting_starts_at',
        'betting_closes_at',
        'result_at',

        'small_multiplier',
        'draw_multiplier',
        'big_multiplier',

        'dice_one',
        'dice_two',
        'total',
        'winning_side',

        'result_mode',
        'modified_by',
        'modified_at',

        'status',

        'total_bet_amount',
        'total_payout_amount',

        'metadata',
    ];

    protected $casts = [
        'betting_starts_at'   => 'datetime',
        'betting_closes_at'  => 'datetime',
        'result_at'          => 'datetime',
        'modified_at'        => 'datetime',

        'small_multiplier'   => 'decimal:2',
        'draw_multiplier'    => 'decimal:2',
        'big_multiplier'     => 'decimal:2',

        'total_bet_amount'   => 'decimal:2',
        'total_payout_amount'=> 'decimal:2',

        'metadata'           => 'array',
    ];

    // Relationships
   
    public function game()
    {
        return $this->belongsTo(Game::class);
    }

    public function round()
    {
        return $this->belongsTo(GameRound::class, 'round_id');
    }

    public function modifiedBy()
    {
        return $this->belongsTo(Admin::class, 'modified_by');
    }

    //Helpers
    
    public function isBettingOpen(): bool
    {
        return $this->status === 'betting_open';
    }

    public function isSettled(): bool
    {
        return $this->status === 'settled';
    }
}