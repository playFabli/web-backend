<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('arena_matches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->boolean('is_robot')->default(false);
            $table->unsignedBigInteger('opponent_user_id')->nullable();
            $table->string('opponent_name')->default('Opponent');

            $table->integer('player_attack')->default(0);
            $table->integer('player_defense')->default(0);
            $table->integer('player_max_hp')->default(0);
            $table->integer('player_hp')->default(0);
            $table->integer('player_energy')->default(100);
            $table->boolean('player_defending')->default(false);

            $table->integer('opponent_attack')->default(0);
            $table->integer('opponent_defense')->default(0);
            $table->integer('opponent_max_hp')->default(0);
            $table->integer('opponent_hp')->default(0);
            $table->integer('opponent_energy')->default(100);
            $table->boolean('opponent_defending')->default(false);

            $table->string('status')->default('ongoing'); // ongoing | won | lost | quit
            $table->unsignedBigInteger('tokens_reward')->default(0);
            $table->unsignedBigInteger('xp_reward')->default(0);
            $table->json('log')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arena_matches');
    }
};