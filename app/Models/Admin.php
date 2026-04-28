<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;
use Laravel\Passport\HasApiTokens;
use App\Models\AdminProfile;

class Admin extends Authenticatable
{
    use HasFactory, Notifiable, HasRoles, HasApiTokens;

    protected $guard_name = 'admin';

    protected $fillable = [
        'email',
        'password',
        'status'
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'status' => 'boolean',
        'password' => 'hashed',
    ];

    public function profile()
    {
        return $this->hasOne(AdminProfile::class);
    }
}
