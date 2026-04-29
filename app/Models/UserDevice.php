<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserDevice extends Model
{
    protected $fillable = [
        'user_id',
        'device_type',
        'device_token',
        'ip_address',
        'user_agent',
        'last_active_at',
        'is_active',
    ];

    protected $casts = [
        'last_active_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
