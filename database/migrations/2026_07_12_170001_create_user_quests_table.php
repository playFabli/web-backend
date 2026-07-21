<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_quests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('quest_definition_id');
            $table->string('type'); // daily, weekly, challenge
            $table->string('name');
            $table->text('description')->nullable();
            $table->integer('required_value');
            $table->integer('current_value')->default(0);
            $table->integer('coin_reward');
            $table->integer('exp_reward');
            $table->boolean('is_claimed')->default(false);
            $table->boolean('is_completed')->default(false);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('quest_definition_id')->references('id')->on('quest_definitions')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_quests');
    }
};