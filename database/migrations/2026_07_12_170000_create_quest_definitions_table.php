<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quest_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('type'); // daily, weekly, challenge
            $table->integer('min_value');
            $table->integer('max_value');
            $table->integer('coin_reward');
            $table->integer('exp_reward');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quest_definitions');
    }
};