<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ArenaDailyChallenge extends Model
{
    protected $fillable = [
        'user_id',
        'key',
        'title',
        'description',
        'required_value',
        'current_value',
        'token_reward',
        'exp_reward',
        'is_completed',
        'is_claimed',
        'expires_at',
    ];

    protected $casts = [
        'is_completed' => 'boolean',
        'is_claimed' => 'boolean',
        'expires_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
