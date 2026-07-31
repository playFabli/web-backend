<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'style'])]
class ForumTag extends Model
{
    public function inventories()
    {
        return $this->hasMany(ForumTagInventory::class);
    }
}
