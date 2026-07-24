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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // recipient/seller
            $table->foreignId('from_user_id')->constrained('users')->cascadeOnDelete(); // buyer/payer
            $table->string('type'); // clothing, game, reselling
            $table->unsignedBigInteger('item_id')->nullable(); // clothing/reselling reference
            $table->unsignedBigInteger('reference_id')->nullable(); // game sale or other ref
            $table->integer('amount');
            $table->enum('status', ['pending', 'approved', 'denied'])->default('pending');
            $table->text('admin_note')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
