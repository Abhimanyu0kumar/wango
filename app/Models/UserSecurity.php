<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserSecurity extends Model
{
    protected $table = 'user_security';
    protected $primaryKey = 'user_id';
    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'two_factor_enabled',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'password_version',
        'last_password_change_at',
        'failed_login_attempts',
        'lockout_until',
    ];

    protected $casts = [
        'two_factor_enabled' => 'boolean',
        'two_factor_recovery_codes' => 'array',
        'last_password_change_at' => 'datetime',
        'lockout_until' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
