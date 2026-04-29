<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Game extends Model
{
    protected $fillable = [
        'category_id',
        'name',
        'slug',
        'engine_key',
        'provider',
        'min_bet',
        'max_bet',
        'metadata',
        'status',
    ];

    protected $casts = [
        'min_bet' => 'decimal:2',
        'max_bet' => 'decimal:2',
        'metadata' => 'array',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function rounds()
    {
        return $this->hasMany(GameRound::class);
    }

    public function bets()
    {
        return $this->hasMany(Bet::class);
    }
}
