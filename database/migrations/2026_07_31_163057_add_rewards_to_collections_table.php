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
        Schema::table('collections', function (Blueprint $table) {
            $table->foreignId('forum_tag_id')->nullable()->after('image')->constrained()->nullOnDelete();
            $table->integer('coin_reward')->default(0)->after('forum_tag_id');
            $table->integer('xp_reward')->default(0)->after('coin_reward');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('collections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('forum_tag_id');
            $table->dropColumn('coin_reward');
            $table->dropColumn('xp_reward');
        });
    }
};
