<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Collection extends Model
{
    protected $fillable = [
        'name',
        'description',
        'image',
    ];

    public function items()
    {
        return $this->belongsToMany(MarketplaceItem::class, 'collection_item', 'collection_id', 'marketplace_item_id');
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'user_collection', 'collection_id', 'user_id');
    }
}
