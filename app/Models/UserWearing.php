<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserWearing extends Model
{
    protected $table = 'user_wearing';
    
    protected $fillable = [
        'user_id',
        'item_id'
    ];
    
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    
    public function item()
    {
        return $this->belongsTo(MarketplaceItem::class);
    }
}