<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserProfile extends Model
{
    protected $primaryKey = 'user_id';
    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'name',
        'avatar_url',
        'date_of_birth',
        'gender',
        'country_code',
        'preferred_currency',
        'address_data',
        'kyc_status',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'address_data' => 'array',
        'kyc_status' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
