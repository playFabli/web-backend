<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Game extends Model
{
    protected $fillable = [
        'title',
        'description',
        'creator_id',
        'thumbnail_url',
        'plays_count',
        'genre',
        'max_players',
        'likes_count',
        'dislikes_count',
    ];

    protected $appends = ['like_ratio'];

    public function getLikeRatioAttribute()
    {
        $total = $this->likes_count + $this->dislikes_count;
        if ($total === 0) {
            return 0;
        }
        return round(($this->likes_count / $total) * 100);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function comments()
    {
        return $this->hasMany(GameComment::class);
    }

    public function votes()
    {
        return $this->hasMany(GameVote::class);
    }
}
