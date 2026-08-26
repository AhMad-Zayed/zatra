<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bus/Fleet redesign Ticket 1 — the actual "bus 1 / bus 2 on this trip" fact rows. Multiple
     * rows per trip_instance_id is what supports "open bus 2 when bus 1 fills, capacity becomes
     * 40+40=80" with zero further schema change: it's just another row.
     *
     * ownership_type='owned': vehicle_id points at a reusable Vehicle master record; the rented_*
     * columns stay null. ownership_type='rented': vehicle_id stays null and the rented_* columns
     * capture that specific trip's bus inline (supplier name as free text — see Phase 0 Section B
     * for why a dedicated TransportSupplier table isn't warranted: unlike a hotel, the physical
     * vehicle itself is NOT the same one each time even when the same rental company recurs, so
     * there's no reusable "same real-world object" to model, only a recurring string).
     *
     * capacity is always set directly on this row (copied from vehicle.default_capacity for
     * owned, entered by staff for rented) — this table, not Vehicle, is the source Ticket 2 will
     * sum to recalculate trip_instances.available_seats, so it must be self-sufficient even when
     * vehicle_id is null.
     *
     * driver_type/guide_type dual-mode: 'internal' uses the matching *_staff_id FK (an existing
     * User, e.g. via StaffResource); 'external' uses the matching *_name/*_phone free-text pair.
     * Both roles share this exact same shape, structurally identical by design (see
     * TripBusAssignment::personFieldsSchema()).
     */
    public function up(): void
    {
        Schema::create('trip_bus_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trip_instance_id')->constrained()->cascadeOnDelete();

            $table->string('ownership_type'); // 'owned' | 'rented' — see App\Enums\BusOwnershipTypeEnum
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->restrictOnDelete();
            $table->unsignedInteger('capacity');
            $table->string('rented_supplier_name')->nullable();
            $table->string('rented_plate_number')->nullable();

            $table->string('driver_type'); // 'internal' | 'external' — see App\Enums\AssignmentPersonTypeEnum
            $table->foreignId('driver_staff_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('driver_name')->nullable();
            $table->string('driver_phone')->nullable();

            $table->string('guide_type'); // 'internal' | 'external'
            $table->foreignId('guide_staff_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('guide_name')->nullable();
            $table->string('guide_phone')->nullable();

            $table->unsignedInteger('sort_order')->default(0);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_bus_assignments');
    }
};
