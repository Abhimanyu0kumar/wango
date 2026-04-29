<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserVipStatus extends Model
{
    protected $table = 'user_vip_status';

    protected $fillable = [
        'user_id',
        'vip_tier_id',
        'points',
        'achieved_at',
        'expires_at',
    ];

    protected $casts = [
        'points' => 'integer',
        'achieved_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function vipTier()
    {
        return $this->belongsTo(VipTier::class, 'vip_tier_id');
    }
}
