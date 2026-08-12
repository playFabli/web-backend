<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ArenaMatch extends Model
{
    protected $fillable = [
        'user_id',
        'is_robot',
        'opponent_user_id',
        'opponent_name',
        'player_attack',
        'player_defense',
        'player_max_hp',
        'player_hp',
        'player_energy',
        'player_defending',
        'opponent_attack',
        'opponent_defense',
        'opponent_max_hp',
        'opponent_hp',
        'opponent_energy',
        'opponent_defending',
        'status',
        'tokens_reward',
        'xp_reward',
        'log',
    ];

    protected function casts(): array
    {
        return [
            'is_robot' => 'boolean',
            'player_defending' => 'boolean',
            'opponent_defending' => 'boolean',
            'log' => 'array',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function opponent()
    {
        return $this->belongsTo(User::class, 'opponent_user_id');
    }
}