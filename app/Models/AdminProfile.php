<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminProfile extends Model
{
    protected $fillable = [
        'admin_id',
        'name',
        'phone',
        'avatar_url',
        'department',
        'designation',
        'timezone',
        'locale',
    ];

    public function admin()
    {
        return $this->belongsTo(Admin::class);
    }
}
