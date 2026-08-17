<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('arena_matches', function (Blueprint $table) {
            $table->timestamp('parry_deadline')->nullable()->after('opponent_intent');
        });
    }

    public function down(): void
    {
        Schema::table('arena_matches', function (Blueprint $table) {
            $table->dropColumn('parry_deadline');
        });
    }
};
