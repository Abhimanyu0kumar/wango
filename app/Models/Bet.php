<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Bet extends Model
{
    protected $fillable = [
        'user_id',
        'wallet_id',
        'game_id',
        'round_id',
        'bet_code',
        'amount',
        'odds',
        'selection',
        'potential_win',
        'payout_amount',
        'status',
        'placed_at',
        'settled_at',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'odds' => 'decimal:4',
        'potential_win' => 'decimal:2',
        'payout_amount' => 'decimal:2',
        'selection' => 'array',
        'placed_at' => 'datetime',
        'settled_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function wallet()
    {
        return $this->belongsTo(WalletAccount::class, 'wallet_id');
    }

    public function game()
    {
        return $this->belongsTo(Game::class);
    }

    public function round()
    {
        return $this->belongsTo(GameRound::class, 'round_id');
    }

    public function settlement()
    {
        return $this->hasOne(Settlement::class);
    }
}
