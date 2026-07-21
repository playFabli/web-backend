<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Petition extends Model
{
    protected $fillable = [
        'user_id',
        'title',
        'description',
        'type',
        'upvotes',
        'downvotes',
        'approved'
    ];

    protected $casts = [
        'approved' => 'boolean'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function votes()
    {
        return $this->hasMany(PetitionVote::class);
    }
}