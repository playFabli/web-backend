<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // marketplace_items - composite index for the items() listing query
        Schema::table('marketplace_items', function (Blueprint $table) {
            $table->index(['category_id', 'is_deleted', 'moderation_status', 'price', 'rap', 'created_at'], 'idx_items_listing');
            $table->index('user_id', 'idx_items_user_id');
            $table->index(['is_deleted', 'moderation_status'], 'idx_items_moderation');
        });

        // marketplace_item_inventories - for owns/buy/inventory lookups
        Schema::table('marketplace_item_inventories', function (Blueprint $table) {
            $table->index(['item_id', 'user_id'], 'idx_inventories_item_user');
            $table->index('user_id', 'idx_inventories_user_id');
        });

        // marketplace_sell_requests - for sell request queries
        Schema::table('marketplace_sell_requests', function (Blueprint $table) {
            $table->index('item_id', 'idx_sell_requests_item_id');
            $table->index('inventory_id', 'idx_sell_requests_inventory_id');
            $table->index('user_id', 'idx_sell_requests_user_id');
        });

        // marketplace_comments - for comment queries
        Schema::table('marketplace_comments', function (Blueprint $table) {
            $table->index('item_id', 'idx_comments_item_id');
        });

        // marketplace_case_contents - for case content queries
        Schema::table('marketplace_case_contents', function (Blueprint $table) {
            $table->index('case_id', 'idx_case_contents_case_id');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_items', function (Blueprint $table) {
            $table->dropIndex('idx_items_listing');
            $table->dropIndex('idx_items_user_id');
            $table->dropIndex('idx_items_moderation');
        });

        Schema::table('marketplace_item_inventories', function (Blueprint $table) {
            $table->dropIndex('idx_inventories_item_user');
            $table->dropIndex('idx_inventories_user_id');
        });

        Schema::table('marketplace_sell_requests', function (Blueprint $table) {
            $table->dropIndex('idx_sell_requests_item_id');
            $table->dropIndex('idx_sell_requests_inventory_id');
            $table->dropIndex('idx_sell_requests_user_id');
        });

        Schema::table('marketplace_comments', function (Blueprint $table) {
            $table->dropIndex('idx_comments_item_id');
        });

        Schema::table('marketplace_case_contents', function (Blueprint $table) {
            $table->dropIndex('idx_case_contents_case_id');
        });
    }
};
