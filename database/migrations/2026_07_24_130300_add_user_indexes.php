<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // user_wearing - for avatar wearing queries
        try { Schema::table('user_wearing', fn (Blueprint $t) => $t->index(['user_id', 'item_id'], 'idx_wearing_user_item')); } catch (\Exception $e) {}

        // petitions - for petition listing
        try { Schema::table('petitions', fn (Blueprint $t) => $t->index('created_at', 'idx_petitions_created_at')); } catch (\Exception $e) {}

        // petition_votes - for vote lookups
        try { Schema::table('petition_votes', fn (Blueprint $t) => $t->index(['petition_id', 'user_id'], 'idx_petition_votes_petition_user')); } catch (\Exception $e) {}

        // marketplace_item_inventories - additional index for user inventory queries
        try { Schema::table('marketplace_item_inventories', fn (Blueprint $t) => $t->index(['user_id', 'item_id'], 'idx_inventories_user_item')); } catch (\Exception $e) {}
    }

    public function down(): void
    {
        try { Schema::table('user_wearing', fn (Blueprint $t) => $t->dropIndex('idx_wearing_user_item')); } catch (\Exception $e) {}
        try { Schema::table('petitions', fn (Blueprint $t) => $t->dropIndex('idx_petitions_created_at')); } catch (\Exception $e) {}
        try { Schema::table('petition_votes', fn (Blueprint $t) => $t->dropIndex('idx_petition_votes_petition_user')); } catch (\Exception $e) {}
        try { Schema::table('marketplace_item_inventories', fn (Blueprint $t) => $t->dropIndex('idx_inventories_user_item')); } catch (\Exception $e) {}
    }
};
