<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable("item_id",
            "user_id",
            "inventory_id",
            "price")]
class MarketplaceSellRequest extends Model
{
    public function item() {
        return $this->belongsTo(MarketplaceItem::class, 'item_id');
    }

    public function user() {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function inventory() {
        return $this->belongsTo(MarketplaceItemInventory::class, 'inventory_id');
    }
}
