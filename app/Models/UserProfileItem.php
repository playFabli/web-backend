<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserProfileItem extends Model
{
    protected $fillable = [
        'user_id',
        'item_id',
        'sort_order',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function item()
    {
        return $this->belongsTo(MarketplaceItem::class, 'item_id');
    }
}
