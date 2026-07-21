<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

#[Fillable("category_id", "title", "content", "user_id")]
class ForumThread extends Model
{
    protected $appends = ['reply_count', 'last_post', 'view_count'];

    public function user() {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function category() {
        return $this->belongsTo(ForumCategory::class, 'category_id');
    }

    public function replies() {
        return $this->hasMany(ForumReply::class, 'thread_id');
    }

    public function views() {
        return $this->hasMany(ForumThreadView::class, 'thread_id');
    }

    public function getReplyCountAttribute() {
        return $this->replies()->count();
    }

    public function getViewCountAttribute() {
        return $this->views()->count();
    }
    
    public function getContentAttribute($value) {
        if ($this->is_scrubbed) {
            return 'Deleted';
        }

        return $value;
    }

    public function getLastPostAttribute() {
        $lastReply = $this->replies()->with('user')->latest()->first();
        if($lastReply) {
            return $lastReply;
        } else {
            return null;
        }
    }
}
