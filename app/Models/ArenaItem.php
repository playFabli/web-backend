<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ArenaItem extends Model
{
    protected $fillable = [
        'item_id',
        'attack',
        'defense',
    ];

    public function item()
    {
        return $this->belongsTo(MarketplaceItem::class, 'item_id');
    }

    public function moves()
    {
        return $this->hasMany(ArenaMove::class)->orderBy('position');
    }
}