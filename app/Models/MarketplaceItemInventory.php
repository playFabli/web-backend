<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(["item_id","user_id","serial","price"])]
class MarketplaceItemInventory extends Model
{
    public function user() {
        return $this->belongsTo(User::class);
    }

    public function item() {
        return $this->belongsTo(MarketplaceItem::class);
    }
}
