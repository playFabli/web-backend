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
         Schema::table('users', function (Blueprint $table) {
            $table->bigInteger('final_rap')->default(0)->index();
        });

        DB::statement("
            UPDATE users u
            SET u.final_rap = COALESCE((
                SELECT SUM(mi.rap)
                FROM marketplace_item_inventories mii
                JOIN marketplace_items mi ON mii.item_id = mi.id
                WHERE mii.user_id = u.id AND mi.is_limited = 1
            ), 0)
        ");
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
