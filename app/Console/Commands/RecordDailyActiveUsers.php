<?php

namespace App\Console\Commands;

use App\Models\DailyActiveUser;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;

class RecordDailyActiveUsers extends Command
{
    protected $signature = 'dau:record';

    protected $description = 'Record yesterday\'s daily active user count into the daily_active_users table';

    public function handle(): void
    {
        $yesterday = Carbon::yesterday()->startOfDay();
        $today = Carbon::today();

        $count = User::where('last_seen_at', '>=', $yesterday)
            ->where('last_seen_at', '<', $today)
            ->count();

        DailyActiveUser::updateOrCreate(
            ['date' => $yesterday->toDateString()],
            ['count' => $count]
        );

        $this->info("Recorded {$count} active users for {$yesterday->toDateString()}");
    }
}
