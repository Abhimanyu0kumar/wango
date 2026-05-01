<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserSecurity extends Model
{
    protected $fillable = [
        'user_id',
        'two_factor_enabled',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'password_version',
        'last_password_change_at',
        'failed_login_attempts',
        'lock_out_until',
    ];

    protected $casts = [
        'two_factor_enabled' => 'boolean',
        'two_factor_recovery_codes' => 'array',
        'last_password_change_at' => 'datetime',
        'lock_out_until' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
