<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('arena_matches', function (Blueprint $table) {
            $table->json('player_moves')->nullable();
            $table->json('opponent_moves')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('arena_matches', function (Blueprint $table) {
            $table->dropColumn(['player_moves', 'opponent_moves']);
        });
    }
};
