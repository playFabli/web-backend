<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserQuest extends Model
{
    protected $fillable = [
        'user_id',
        'quest_definition_id',
        'type',
        'name',
        'description',
        'required_value',
        'current_value',
        'coin_reward',
        'exp_reward',
        'is_claimed',
        'is_completed',
        'expires_at',
    ];

    protected $casts = [
        'is_claimed' => 'boolean',
        'is_completed' => 'boolean',
        'expires_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function questDefinition()
    {
        return $this->belongsTo(QuestDefinition::class);
    }
}
