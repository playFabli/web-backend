<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminLog extends Model
{
    protected $fillable = [
        'admin_id',
        'target_id',
        'log',
    ];

    public function admin() {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function target() {
        return $this->belongsTo(User::class, 'target_id');
    }
}