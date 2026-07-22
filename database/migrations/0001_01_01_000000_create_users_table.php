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
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('username');
            $table->string('email')->unique();
            $table->string('password');
            $table->integer('coins');
            $table->integer('rap')->default(0);
            $table->integer('level')->default(1);
            $table->integer('exp')->default(0);
            $table->enum('role', ['user', 'moderator', 'admin', 'banned'])->default('user');
            $table->string('description')->default("Hey, I'm new to Fabli!");
            $table->string('bubble')->default("How's it going?");
            $table->timestamp('last_currency_at')->default(now());
            $table->timestamp('last_seen_at')->default(now());
            $table->timestamps();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
