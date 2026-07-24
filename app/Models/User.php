<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

#[Fillable(['username', 'email', 'password', 'coins', 'is_email_verified'])]
#[Hidden(['password', 'email', 'last_currency_at'])]
class User extends Model
{
    protected $appends = ['is_online', 'leaderboard_rank', 'leaderboard_percentile', 'item_count'];

    public function getIsOnlineAttribute()
    {
        if (! $this->last_seen_at) {
            return false;
        }

        $last = Carbon::parse($this->last_seen_at);

        return $last->greaterThanOrEqualTo(Carbon::now()->subSeconds(10));
    }

    // public function getFinalRapAttribute()
    // {
    //     $itemsOwned = MarketplaceItemInventory::where('user_id', $this->id)->get();
    //     $rap = 0;

    //     foreach ($itemsOwned as $inv) {
    //         if (! $inv->item->is_limited) {
    //             continue;
    //         } else {
    //             $rap = $rap + $inv->item->final_rap;
    //         }
    //     }

    //     return $rap;
    // }

    public function recalculateStats()
    {
        $totalRap = DB::table('marketplace_item_inventories')
            ->join('marketplace_items', 'marketplace_item_inventories.item_id', '=', 'marketplace_items.id')
            ->where('marketplace_item_inventories.user_id', $this->id)
            ->where('marketplace_items.is_limited', true)
            ->sum('marketplace_items.rap');

        $totalItems = DB::table('marketplace_item_inventories')
            ->where('user_id', $this->id)
            ->count();

        $this->final_rap = $totalRap;
        $this->item_count = $totalItems;
        $this->save();
    }

    public function getLeaderboardPercentileAttribute(): ?float
    {
        $finalRap = $this->final_rap;

        $allUsers = self::all();
        $totalUsers = $allUsers->count();

        if ($totalUsers <= 1) {
            return $totalUsers === 1 ? 0.00 : null;
        }

        $usersWithHigherRap = $allUsers->filter(function ($user) use ($finalRap) {
            return $user->final_rap > $finalRap;
        })->count();

        return round(($usersWithHigherRap / $totalUsers) * 100) + 1;
    }

    public function getLeaderboardRankAttribute()
    {
        $users = self::all()->sortByDesc(function ($user) {
            return $user->final_rap;
        })->values();

        $rank = $users->search(function ($user) {
            return $user->id === $this->id;
        });

        return $rank === false ? null : $rank + 1;
    }

    public function getItemCountAttribute()
    {
        return MarketplaceItemInventory::where('user_id', $this->id)->count();
    }

    public function expNeeded()
    {
        $expNeeded = floor(10 * $this->level * log($this->level + 1) * 1.25);

        return $expNeeded;
    }

    public function giveExp(int $amount)
    {
        $expNeeded = $this->expNeeded();

        if ($this->exp + $amount >= $expNeeded) {
            $this->level = $this->level + 1;
            $this->exp = 0;

            $this->save();
        } else {
            $this->exp = $this->exp + $amount;
            $this->save();
        }
    }

    public function privacy()
    {
        return $this->hasOne(UserPrivacySetting::class);
    }

    public function bans()
    {
        return $this->hasMany(UserBan::class, 'user_id');
    }

    public function wearing()
    {
        return $this->hasMany(UserWearing::class);
    }

    public function avatarColors()
    {
        return $this->hasOne(UserAvatarColor::class);
    }

    public function inventory()
    {
        return $this->hasMany(MarketplaceItemInventory::class);
    }
}
