<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Booking-time room-TYPE selection (quantity + occupancy), analogous in role to
     * booking_addons — a snapshot of a catalog choice, not the actual per-passenger rooming
     * list. No per-passenger assignment yet (that's Ticket 3's RoomAssignment, separate table).
     * price_at_booking is integer cents (the modern convention already used by RoomType's own
     * price fields, Booking.grand_total, etc.) rather than booking_addons' older decimal(10,2).
     */
    public function up(): void
    {
        Schema::create('booking_room_selections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_type_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('occupancy_type'); // 'shared' | 'single' — see App\Enums\OccupancyTypeEnum
            $table->integer('price_at_booking');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_room_selections');
    }
};
