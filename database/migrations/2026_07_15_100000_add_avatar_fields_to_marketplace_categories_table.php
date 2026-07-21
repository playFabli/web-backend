<?php

use App\Models\MarketplaceCategory;
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
        Schema::table('marketplace_categories', function (Blueprint $table) {
            $table->boolean('has_model')->default(false)->after('is_admin_only');
            $table->boolean('has_texture')->default(false)->after('has_model');
            $table->string('parts_affected')->nullable()->after('has_texture');
        });

        $hats = new MarketplaceCategory();
        $hats->title = "Hats";
        $hats->is_admin_only = true;
        $hats->has_model = true;
        $hats->has_texture = true;
        $hats->save();

        $faces = new MarketplaceCategory();
        $faces->title = "Faces";
        $faces->is_admin_only = true;
        $faces->has_model = false;
        $faces->has_texture = true;
        $faces->parts_affected = "head";
        $faces->save();

        $gear = new MarketplaceCategory();
        $gear->title = "Gear";
        $gear->is_admin_only = true;
        $gear->has_model = true;
        $gear->has_texture = true;
        $gear->parts_affected = "";
        $gear->save();

        $boxes = new MarketplaceCategory();
        $boxes->title = "Boxes";
        $boxes->is_admin_only = true;
        $boxes->has_model = false;
        $boxes->has_texture = false;
        $boxes->parts_affected = "";
        $boxes->save();

        $shirts = new MarketplaceCategory();
        $shirts->title = "Shirts";
        $shirts->is_admin_only = false;
        $shirts->has_model = false;
        $shirts->has_texture = true;
        $shirts->parts_affected = "left_arm,torso,right_arm";
        $shirts->save();

        $pants = new MarketplaceCategory();
        $pants->title = "Pants";
        $pants->is_admin_only = false;
        $pants->has_model = false;
        $pants->has_texture = true;
        $pants->parts_affected = "left_leg,right_leg";
        $pants->save();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('marketplace_categories', function (Blueprint $table) {
            $table->dropColumn(['has_model', 'has_texture', 'parts_affected']);
        });
    }
};