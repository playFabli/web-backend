<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('marketplace_items', function (Blueprint $table) {
            $table->boolean('is_timed')->default(false)->after('is_offsale');
            $table->timestamp('timed_end_at')->nullable()->after('is_timed');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('marketplace_items', function (Blueprint $table) {
            $table->dropColumn(['is_timed', 'timed_end_at']);
        });
    }
};
