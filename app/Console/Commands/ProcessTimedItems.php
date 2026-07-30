<?php

namespace App\Console\Commands;

use App\Models\MarketplaceItem;
use Illuminate\Console\Command;

class ProcessTimedItems extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:process-timed-items';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Mark timed items as offsale when their end time has passed';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $count = MarketplaceItem::where('is_timed', true)
            ->where('timed_end_at', '<=', now())
            ->where('is_offsale', false)
            ->update(['is_offsale' => true]);

        $this->info("Processed {$count} timed items.");

        return Command::SUCCESS;
    }
}
