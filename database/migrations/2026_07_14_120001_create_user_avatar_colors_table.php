<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_avatar_colors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('left_arm_color')->default('#D9C5B2');
            $table->string('right_arm_color')->default('#D9C5B2');
            $table->string('torso_color')->default('#D9C5B2');
            $table->string('left_leg_color')->default('#D9C5B2');
            $table->string('right_leg_color')->default('#D9C5B2');
            $table->string('head_color')->default('#D9C5B2');
            $table->timestamps();

            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_avatar_colors');
    }
};
