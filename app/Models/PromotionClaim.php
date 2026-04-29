<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PromotionClaim extends Model
{
    protected $fillable = [
        'user_id',
        'promotion_id',
        'claim_code',
        'amount',
        'status',
        'claimed_at',
        'expires_at',
        'used_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'claimed_at' => 'datetime',
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
