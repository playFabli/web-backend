<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserProfileWall extends Model
{
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
