<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoadmapItem extends Model
{
    protected $fillable = [
        'title',
        'description',
        'status',
        'phase',
        'sort_order'
    ];

    protected $casts = [
        'status' => 'string'
    ];
}