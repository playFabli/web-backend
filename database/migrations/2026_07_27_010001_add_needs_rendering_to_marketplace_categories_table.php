<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_categories', function (Blueprint $table) {
            $table->boolean('needs_rendering')->default(true)->after('parts_affected');
        });

        // Set needs_rendering to false for the 3 specific categories
        \Illuminate\Support\Facades\DB::table('marketplace_categories')
            ->whereIn('title', ['Hats', 'Faces', 'Gear'])
            ->update(['needs_rendering' => false]);
    }

    public function down(): void
    {
        Schema::table('marketplace_categories', function (Blueprint $table) {
            $table->dropColumn('needs_rendering');
        });
    }
};