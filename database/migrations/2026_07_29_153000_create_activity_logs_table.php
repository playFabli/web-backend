<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type'); // e.g. 'purchase', 'forum_post', 'collection_complete', 'level_up', 'item_created', 'quest_complete'
            $table->string('description');
            $table->nullableMorphs('subject'); // polymorphic link to the related model
            $table->json('metadata')->nullable(); // extra data like item name, level, etc.
            $table->timestamps();

            $table->index(['created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
