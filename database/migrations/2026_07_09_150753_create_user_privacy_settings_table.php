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
        Schema::create('user_privacy_settings', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id');
            $table->boolean('profile_visible')->default(true);
            $table->boolean('show_last_online_time')->default(true);
            $table->boolean('show_rap')->default(true);
            $table->integer('who_can_post_on_wall')->default(0);
            $table->integer('who_can_see_inventory')->default(0);
            $table->integer('who_can_trade')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_privacy_settings');
    }
};
