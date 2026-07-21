<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
#[Fillable([
    "from_id",
    "to_id",
    "price",
    "serial","item_id"
])]

class MarketplaceSellRequestHistory extends Model
{
    //
}
