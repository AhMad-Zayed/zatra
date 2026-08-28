<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Admin panel UX audit, Friction Point #4 — fleet/vehicle assignment and driver/guide
     * staffing are often finalized at different times (a trip's bus needs can be locked in weeks
     * before a driver is actually rostered). driver_type/guide_type were originally required at
     * bus-assignment time, forcing both staffing questions to be answered before a bus could even
     * be attached to a trip. Nullable now, so a bus can be added with driver/guide left unset and
     * completed later via editBus.
     */
    public function up(): void
    {
        Schema::table('trip_bus_assignments', function (Blueprint $table) {
            $table->string('driver_type')->nullable()->change();
            $table->string('guide_type')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('trip_bus_assignments', function (Blueprint $table) {
            $table->string('driver_type')->nullable(false)->change();
            $table->string('guide_type')->nullable(false)->change();
        });
    }
};
