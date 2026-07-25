<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->integer('item_count')->default(0)->index();
        });

        DB::statement('
            UPDATE users u
            SET u.item_count = COALESCE((
                SELECT COUNT(*)
                FROM marketplace_item_inventories mii
                WHERE mii.user_id = u.id
            ), 0)
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            //
        });
    }
};
