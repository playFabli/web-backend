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
        Schema::table('site_settings', function (Blueprint $table) {
            // Announcement bar text shown to logged-in users. Defaults to the
            // previously hardcoded announcement so existing installs keep the
            // banner until an admin edits or clears it.
            $table->string('banner_message')->nullable()
                ->default('The Medieval Collection and the Arena are live! Check out our blog post!')
                ->after('marketplace_banner_image');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->dropColumn('banner_message');
        });
    }
};
