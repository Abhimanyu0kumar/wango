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
        'pin_code',
        'last_password_change',
        'failed_login_attempts',
        'locked_until',
    ];

    protected $casts = [
        'two_factor_enabled' => 'boolean',
        'two_factor_recovery_codes' => 'array',
        'last_password_change' => 'datetime',
        'locked_until' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
