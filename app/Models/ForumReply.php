<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable("thread_id", "user_id", "content")]
class ForumReply extends Model
{
    public function user() {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function thread() {
        return $this->belongsTo(ForumThread::class, 'thread_id');
    }

    public function getContentAttribute($value) {
        if ($this->is_scrubbed) {
            return 'Deleted';
        }

        return $value;
    }
}
