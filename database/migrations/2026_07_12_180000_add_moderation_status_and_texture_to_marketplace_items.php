<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_items', function (Blueprint $table) {
            $table->string('texture_path')->nullable()->after('description');
            $table->enum('moderation_status', ['unapproved', 'pending', 'approved'])->default('pending')->after('is_deleted');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_items', function (Blueprint $table) {
            $table->dropColumn('texture_path');
            $table->dropColumn('moderation_status');
        });
    }
};