<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Storefront content-density rebuild: the real Stitch mockup (stich_with_google_store/
 * stitch_admin_panel_arabic_rebranding (1)/(2)) shows a "يشمل / لا يشمل" (includes/excludes)
 * two-column section on trip-details -- there was no data field to support it. Smallest
 * reasonable addition: two nullable JSON string-array columns, matching the exact pattern
 * itinerary_data already established (migration 2026_09_05_000001) rather than inventing a new
 * one. Both null/empty by default -- the section is hidden entirely on trip-details until a real
 * agency admin actually fills them in via TripTemplateResource, never fake/placeholder content.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trip_templates', function (Blueprint $table) {
            $table->json('includes')->nullable()->after('itinerary_data');
            $table->json('excludes')->nullable()->after('includes');
        });
    }

    public function down(): void
    {
        Schema::table('trip_templates', function (Blueprint $table) {
            $table->dropColumn(['includes', 'excludes']);
        });
    }
};
