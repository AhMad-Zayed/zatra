<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Homepage/trip-details visual density ticket, item 3: a destination map needs some real
 * coordinate to center on -- no lat/long (or any geocoding) exists anywhere on TripTemplate or
 * elsewhere in this schema. Smallest viable addition: two nullable decimal columns, admin-
 * editable, matching duration_days/includes/excludes before it -- stays null for every existing
 * template until an agency admin fills them in, and the map section is hidden entirely (not a
 * broken/blank map) until both are set, same graceful-degradation principle as every other
 * optional field added this session.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trip_templates', function (Blueprint $table) {
            $table->decimal('destination_latitude', 10, 7)->nullable()->after('duration_days');
            $table->decimal('destination_longitude', 10, 7)->nullable()->after('destination_latitude');
        });
    }

    public function down(): void
    {
        Schema::table('trip_templates', function (Blueprint $table) {
            $table->dropColumn(['destination_latitude', 'destination_longitude']);
        });
    }
};
