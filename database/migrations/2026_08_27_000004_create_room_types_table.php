<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A room category (single/double/triple/quad) within a specific hotel option.
     * room_count is REAL physical room inventory (fixes the "100 passengers != 100 slots" gap
     * PackageOption.available_seats — a person-count cap — had). capacity_per_room is how many
     * people that room type sleeps. Both price fields are integer cents, no independent
     * currency column — always inherits the trip instance's currency via
     * trip_stay_leg_hotel_option -> trip_stay_leg -> trip_instance, same principle already
     * enforced for Payment/Booking/PackageOption currency elsewhere in this app.
     * price_adjustment_shared: per person, full occupancy. price_adjustment_single_supplement:
     * flat extra charge when a room is booked under-occupied.
     * Hotel/Rooming redesign Phase 1. No inventory ledger yet (Ticket 2) and no RoomAssignment
     * yet (Ticket 3) — this table only defines what a room type IS, not what's been consumed
     * or who's been assigned into one.
     */
    public function up(): void
    {
        Schema::create('room_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trip_stay_leg_hotel_option_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('capacity_per_room');
            $table->unsignedInteger('room_count');
            $table->integer('price_adjustment_shared')->default(0);
            $table->integer('price_adjustment_single_supplement')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_types');
    }
};
