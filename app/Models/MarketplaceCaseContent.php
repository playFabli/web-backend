<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketplaceCaseContent extends Model
{
    public function item()
    {
        return $this->belongsTo(MarketplaceItem::class);
    }
}
