<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuestDefinition extends Model
{
    protected $fillable = [
        'name',
        'description',
        'type',
        'min_value',
        'max_value',
        'coin_reward',
        'exp_reward',
    ];
}