<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserDevice extends Model
{
    protected $fillable = [
        'user_id',
        'device_fingerprint',
        'platform',
        'app_version',
        'push_token',
        'is_trusted',
        'last_seen_at',
        'last_ip',
        'last_user_agent',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'is_trusted' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
