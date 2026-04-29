<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Settlement extends Model
{
    protected $fillable = [
        'bet_id',
        'user_id',
        'result',
        'payout_amount',
        'profit_loss',
        'settled_by',
        'settled_at',
        'notes',
    ];

    protected $casts = [
        'payout_amount' => 'decimal:2',
        'profit_loss' => 'decimal:2',
        'settled_at' => 'datetime',
    ];

    public function bet()
    {
        return $this->belongsTo(Bet::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
