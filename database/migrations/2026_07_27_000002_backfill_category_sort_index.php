<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('marketplace_categories')
            ->where('sort_index', 0)
            ->orWhere('sort_index', null)
            ->update(['sort_index' => DB::raw('id')]);
    }

    public function down(): void
    {
        // Cannot reverse backfill
    }
};
