<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SiteSetting extends Model
{
    protected $fillable = [
        'starting_currency',
        'daily_bonus',
        'maintenance_mode',
        'registration_open',
        'marketplace_banner_image',
        'banner_message',
    ];
}
