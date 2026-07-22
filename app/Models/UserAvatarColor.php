<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserAvatarColor extends Model
{
    protected $table = 'user_avatar_colors';

    protected $fillable = [
        'user_id',
        'left_arm_color',
        'right_arm_color',
        'torso_color',
        'left_leg_color',
        'right_leg_color',
        'head_color',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
