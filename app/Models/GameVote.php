<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GameVote extends Model
{
    protected $fillable = [
        'game_id',
        'user_id',
        'vote',
    ];

    public function game()
    {
        return $this->belongsTo(Game::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
