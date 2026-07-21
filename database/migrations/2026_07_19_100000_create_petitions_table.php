<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('petitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('title');
            $table->text('description');
            $table->enum('type', ['add', 'remove', 'change']);
            $table->integer('upvotes')->default(0);
            $table->integer('downvotes')->default(0);
            $table->boolean('approved')->default(false);
            $table->timestamps();
        });

        Schema::create('petition_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('petition_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->enum('vote', ['upvote', 'downvote']);
            $table->timestamps();
            
            $table->unique(['petition_id', 'user_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('petition_votes');
        Schema::dropIfExists('petitions');
    }
};