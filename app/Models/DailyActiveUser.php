<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailyActiveUser extends Model
{
    protected $fillable = ['date', 'count'];
}
