<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketplaceItem extends Model
{
    protected $fillable = [
        'user_id',
        'category_id',
        'title',
        'description',
        'texture_path',
        'model_path',
        'display_image_path',
        'stylesheet_path',
        'price',
        'rap',
        'rarity',
        'is_limited',
        'stock_count',
        'stock_left',
        'is_offsale',
        'is_deleted',
        'moderation_status',
    ];

    protected $appends = ['sold_count', 'comment_count', 'final_rap'];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function category()
    {
        return $this->belongsTo(MarketplaceCategory::class, 'category_id');
    }

    public function inventories()
    {
        return $this->hasMany(MarketplaceItemInventory::class, 'item_id');
    }

    public function comments()
    {
        return $this->hasMany(MarketplaceComment::class, 'item_id');
    }

    public function collections()
    {
        return $this->belongsToMany(Collection::class, 'collection_item', 'marketplace_item_id', 'collection_id');
    }

    public function getSoldCountAttribute()
    {
        return $this->inventories()->count();
    }

    public function getCommentCountAttribute()
    {
        return $this->comments()->count();
    }

    public function getFinalRapAttribute()
    {
        if (! $this->is_limited) {
            return $this->rap;
        }

        if ($this->stock_left > 0) {
            return $this->rap;
        }

        $prices = MarketplaceSellRequestHistory::where('item_id', $this->id)
            ->orderBy('price', 'asc')
            ->pluck('price')
            ->toArray();

        $rapCount = count($prices);

        if ($rapCount == 0) {
            return $this->rap;
        }

        $middleIndex = floor($rapCount / 2);

        if ($rapCount % 2 !== 0) {
            $medianPrice = $prices[$middleIndex];
        } else {
            $medianPrice = ($prices[$middleIndex - 1] + $prices[$middleIndex]) / 2;
        }

        return $this->rap + $medianPrice;
    }
}
