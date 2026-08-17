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
        'player_moves',
        'opponent_moves',
        'round',
        'player_stamina',
        'opponent_stamina',
        'player_dodge_cd',
        'opponent_dodge_cd',
        'player_ability_cd',
        'opponent_ability_cd',
        'player_combo',
        'opponent_combo',
        'player_vulnerable',
        'opponent_vulnerable',
        'player_exhausted',
        'opponent_exhausted',
        'player_last_action',
        'opponent_last_action',
        'opponent_intent',
        'parry_deadline',
    ];

    protected function casts(): array
    {
        return [
            'is_robot' => 'boolean',
            'player_defending' => 'boolean',
            'opponent_defending' => 'boolean',
            'player_vulnerable' => 'boolean',
            'opponent_vulnerable' => 'boolean',
            'player_exhausted' => 'boolean',
            'opponent_exhausted' => 'boolean',
            'log' => 'array',
            'player_moves' => 'array',
            'opponent_moves' => 'array',
            'opponent_intent' => 'array',
            'parry_deadline' => 'datetime',
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