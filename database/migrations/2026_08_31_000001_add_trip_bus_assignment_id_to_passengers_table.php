<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bus/Fleet redesign Ticket 3 — disambiguates Passenger.seat_number once a trip can have
     * more than one bus (Phase 0 Section C: "seat 15 on Bus A" and "seat 15 on Bus B" are
     * distinct). Nullable and additive: seat_number itself is untouched and keeps working
     * exactly as before for trips with zero or one bus. nullOnDelete (not cascade) — deleting a
     * bus assignment must not delete the passenger, just clear which bus they're on.
     */
    public function up(): void
    {
        Schema::table('passengers', function (Blueprint $table) {
            $table->foreignId('trip_bus_assignment_id')->nullable()->after('seat_number')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('passengers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('trip_bus_assignment_id');
        });
    }
};
