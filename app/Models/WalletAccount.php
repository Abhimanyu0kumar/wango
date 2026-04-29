<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WalletAccount extends Model
{
    protected $fillable = [
        'user_id',
        'currency',
        'available_balance',
        'locked_balance',
        'status',
    ];

    protected $casts = [
        'available_balance' => 'decimal:2',
        'locked_balance' => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function ledgers()
    {
        return $this->hasMany(WalletLedger::class, 'wallet_id');
    }
}
