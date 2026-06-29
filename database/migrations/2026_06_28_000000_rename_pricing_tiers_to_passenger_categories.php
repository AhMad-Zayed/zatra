<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Drop existing foreign keys to prevent constraint errors during rename
        Schema::table('template_pricing_tiers', function (Blueprint $table) {
            $table->dropForeign(['global_pricing_tier_id']);
        });

        Schema::table('passengers', function (Blueprint $table) {
            $table->dropForeign(['trip_pricing_tier_id']);
        });

        // 2. Rename tables
        Schema::rename('global_pricing_tiers', 'passenger_categories');
        Schema::rename('template_pricing_tiers', 'template_passenger_categories');
        Schema::rename('trip_pricing_tiers', 'trip_passenger_categories');

        // 3. Rename columns in referencing tables
        Schema::table('template_passenger_categories', function (Blueprint $table) {
            $table->renameColumn('global_pricing_tier_id', 'passenger_category_id');
        });

        Schema::table('passengers', function (Blueprint $table) {
            $table->renameColumn('trip_pricing_tier_id', 'trip_passenger_category_id');
        });

        // 4. Restore foreign keys with the new names
        Schema::table('template_passenger_categories', function (Blueprint $table) {
            $table->foreign('passenger_category_id')
                  ->references('id')->on('passenger_categories')
                  ->nullOnDelete();
        });

        Schema::table('passengers', function (Blueprint $table) {
            $table->foreign('trip_passenger_category_id')
                  ->references('id')->on('trip_passenger_categories')
                  ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        // 1. Drop the new foreign keys
        Schema::table('template_passenger_categories', function (Blueprint $table) {
            $table->dropForeign(['passenger_category_id']);
        });

        Schema::table('passengers', function (Blueprint $table) {
            $table->dropForeign(['trip_passenger_category_id']);
        });

        // 2. Revert column names
        Schema::table('template_passenger_categories', function (Blueprint $table) {
            $table->renameColumn('passenger_category_id', 'global_pricing_tier_id');
        });

        Schema::table('passengers', function (Blueprint $table) {
            $table->renameColumn('trip_passenger_category_id', 'trip_pricing_tier_id');
        });

        // 3. Revert table names
        Schema::rename('trip_passenger_categories', 'trip_pricing_tiers');
        Schema::rename('template_passenger_categories', 'template_pricing_tiers');
        Schema::rename('passenger_categories', 'global_pricing_tiers');

        // 4. Restore original foreign keys
        Schema::table('template_pricing_tiers', function (Blueprint $table) {
            $table->foreign('global_pricing_tier_id')
                  ->references('id')->on('global_pricing_tiers')
                  ->nullOnDelete();
        });

        Schema::table('passengers', function (Blueprint $table) {
            $table->foreign('trip_pricing_tier_id')
                  ->references('id')->on('trip_pricing_tiers')
                  ->cascadeOnDelete();
        });
    }
};
