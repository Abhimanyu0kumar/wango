<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserKycDocument extends Model
{
    protected $fillable = [
        'user_id',
        'document_type',
        'document_number',
        'front_image_url',
        'back_image_url',
        'selfie_image_url',
        'status',
        'verified_at',
        'verified_by',
        'rejection_reason',
    ];

    protected $casts = [
        'verified_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function verifier()
    {
        return $this->belongsTo(Admin::class, 'verified_by');
    }
}
