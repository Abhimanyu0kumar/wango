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
        'rejection_reason',
        'submitted_at',
        'reviewed_at',
        'reviewed_by',
        'expires_at',
        'metadata',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'expires_at' => 'datetime',
        'metadata' => 'array',
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
