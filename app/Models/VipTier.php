<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VipTier extends Model
{
    protected $fillable = [
        'name',
        'level',
        'min_points',
        'cashback_rate',
        'withdrawal_limit',
        'bonus_rate',
        'icon_url',
        'description',
        'is_active',
    ];

    protected $casts = [
        'level' => 'integer',
        'min_points' => 'integer',
        'cashback_rate' => 'decimal:2',
        'bonus_rate' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function userStatuses()
    {
        return $this->hasMany(UserVipStatus::class, 'vip_tier_id');
    }
}
