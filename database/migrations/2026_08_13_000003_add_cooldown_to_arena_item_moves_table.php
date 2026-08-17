<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('arena_item_moves', function (Blueprint $table) {
            $table->unsignedTinyInteger('cooldown')->default(0)->after('damage');
        });
    }

    public function down(): void
    {
        Schema::table('arena_item_moves', function (Blueprint $table) {
            $table->dropColumn('cooldown');
        });
    }
};
