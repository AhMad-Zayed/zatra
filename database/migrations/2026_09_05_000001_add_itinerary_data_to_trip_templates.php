<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Storefront redesign Phase F, item 5: the trip-details page has had a full day-by-day
     * itinerary timeline UI since before this ticket (trip-details.blade.php), but it read
     * $template->itinerary_data -- a column that never existed anywhere in the schema, so the
     * section silently showed "no itinerary yet" for every trip, forever. This makes that UI
     * functional.
     */
    public function up(): void
    {
        Schema::table('trip_templates', function (Blueprint $table) {
            $table->json('itinerary_data')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('trip_templates', function (Blueprint $table) {
            $table->dropColumn('itinerary_data');
        });
    }
};
