<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PetitionVote extends Model
{
    protected $fillable = [
        'petition_id',
        'user_id',
        'vote',
    ];

    public function petition()
    {
        return $this->belongsTo(Petition::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
