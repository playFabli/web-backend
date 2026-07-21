<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserFriendRequest extends Model
{
    public function from() {
        return $this->belongsTo(User::class, 'from_id');
    }

    public function to() {
        return $this->belongsTo(User::class, 'to_id');
    }
}
