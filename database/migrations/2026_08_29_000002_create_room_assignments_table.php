<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which specific passenger occupies which specific room_instance — the actual per-passenger
     * rooming list. passenger_id is UNIQUE: a passenger occupies exactly one room at a time: a
     * "move" is an update, not a second row. booking_id is denormalized from passenger.booking_id
     * (technically derivable, but stored directly — same convention as room_inventory_ledgers
     * storing booking_id) since it's read constantly: grouping the unassigned pool, the
     * auto-assign keep-together preference, and the printed rooming list all key off it.
     * assigned_by records which staff member placed this passenger (null for auto-assign).
     * Fully additive/new — no existing table is touched. Hotel/Rooming redesign Ticket 3.
     */
    public function up(): void
    {
        Schema::create('room_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_instance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('passenger_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_assignments');
    }
};
