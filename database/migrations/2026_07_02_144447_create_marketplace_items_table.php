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
        Schema::create('marketplace_items', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id');
            $table->integer('category_id');
            $table->string('title');
            $table->text('description');
            $table->integer('price');
            $table->integer('rap')->default(0);
            $table->enum('rarity', ['none', 'uncommon', 'rare', 'ultra_rare', 'legendary'])->default('none');
            $table->boolean('is_limited')->default(false);
            $table->integer('stock_count')->default(0);
            $table->integer('stock_left')->default(0);
            $table->boolean('is_offsale')->default(false);
            $table->boolean('is_deleted')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('marketplace_items');
    }
};
