<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('arena_item_moves', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('arena_item_id');
            $table->unsignedTinyInteger('position')->default(1); // 1 or 2 (first/second move)
            $table->string('name');
            $table->integer('damage')->default(0);
            $table->string('border_color', 20)->default('#A2574F');
            $table->timestamps();

            $table->foreign('arena_item_id')
                ->references('id')
                ->on('arena_items')
                ->cascadeOnDelete();

            $table->unique(['arena_item_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arena_item_moves');
    }
};
