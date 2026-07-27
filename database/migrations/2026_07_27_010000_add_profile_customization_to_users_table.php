<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('profile_theme')->nullable()->after('description');
            $table->string('profile_banner')->nullable()->after('profile_theme');
            $table->string('avatar_frame')->nullable()->after('profile_banner');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['profile_theme', 'profile_banner', 'avatar_frame']);
        });
    }
};