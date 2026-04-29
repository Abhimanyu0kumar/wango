<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Reward extends Model
{
    protected $fillable = [
        'name',
        'description',
        'type',
        'value',
        'points_required',
        'quantity',
        'is_active',
        'expires_at',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'points_required' => 'integer',
        'quantity' => 'integer',
        'is_active' => 'boolean',
        'expires_at' => 'datetime',
    ];

    public function userRewards()
    {
        return $this->hasMany(UserReward::class);
    }
}
