<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('arena_matches', function (Blueprint $table) {
            $table->unsignedInteger('round')->default(1)->after('status');
            $table->unsignedSmallInteger('player_stamina')->default(100)->after('round');
            $table->unsignedSmallInteger('opponent_stamina')->default(100)->after('player_stamina');
            $table->unsignedTinyInteger('player_dodge_cd')->default(0)->after('opponent_stamina');
            $table->unsignedTinyInteger('opponent_dodge_cd')->default(0)->after('player_dodge_cd');
            $table->unsignedTinyInteger('player_ability_cd')->default(0)->after('opponent_dodge_cd');
            $table->unsignedTinyInteger('opponent_ability_cd')->default(0)->after('player_ability_cd');
            $table->unsignedTinyInteger('player_combo')->default(0)->after('opponent_ability_cd');
            $table->unsignedTinyInteger('opponent_combo')->default(0)->after('player_combo');
            $table->boolean('player_vulnerable')->default(false)->after('opponent_combo');
            $table->boolean('opponent_vulnerable')->default(false)->after('player_vulnerable');
            $table->boolean('player_exhausted')->default(false)->after('opponent_vulnerable');
            $table->boolean('opponent_exhausted')->default(false)->after('player_exhausted');
            $table->string('player_last_action', 20)->nullable()->after('opponent_exhausted');
            $table->string('opponent_last_action', 20)->nullable()->after('player_last_action');
            $table->json('opponent_intent')->nullable()->after('opponent_last_action');
        });
    }

    public function down(): void
    {
        Schema::table('arena_matches', function (Blueprint $table) {
            $table->dropColumn([
                'round',
                'player_stamina',
                'opponent_stamina',
                'player_dodge_cd',
                'opponent_dodge_cd',
                'player_ability_cd',
                'opponent_ability_cd',
                'player_combo',
                'opponent_combo',
                'player_vulnerable',
                'opponent_vulnerable',
                'player_exhausted',
                'opponent_exhausted',
                'player_last_action',
                'opponent_last_action',
                'opponent_intent',
            ]);
        });
    }
};
