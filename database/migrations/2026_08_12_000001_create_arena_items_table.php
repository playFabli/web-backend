<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('arena_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('item_id');
            $table->integer('attack')->default(0);
            $table->integer('defense')->default(0);
            $table->timestamps();

            $table->unique('item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arena_items');
    }
};