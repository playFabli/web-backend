<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ArenaMove extends Model
{
    protected $table = "arena_item_moves";
    protected $fillable = [
        'arena_item_id',
        'position',
        'name',
        'damage',
        'cooldown',
        'border_color',
    ];

    public function arenaItem()
    {
        return $this->belongsTo(ArenaItem::class);
    }
}
