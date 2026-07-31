<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['title', 'body', 'banner_path', 'user_id', 'is_published', 'is_featured', 'is_deleted'])]
class BlogPost extends Model
{
    protected $appends = ['short_body'];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Return a plain-text excerpt of the body for card previews.
     * Strips BBCode tags and truncates to 200 characters.
     */
    public function getShortBodyAttribute()
    {
        $plain = preg_replace('/\[(\/?)(b|i|u|img|url|quote|code|center)([^\]]*)\]/i', '', $this->body);

        if (mb_strlen($plain) <= 200) {
            return $plain;
        }

        return mb_substr($plain, 0, 200).'...';
    }
}
