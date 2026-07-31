<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'forum_tag_id'])]
class ForumTagInventory extends Model
{
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function forumTag()
    {
        return $this->belongsTo(ForumTag::class);
    }
}
