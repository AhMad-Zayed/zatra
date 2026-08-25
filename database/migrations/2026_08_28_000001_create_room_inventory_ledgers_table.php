<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mirrors inventory_ledgers (seats) exactly, as a fully separate table — deliberately never
     * sharing rows, locks, or state with the seat ledger, per the Hotel/Rooming Ticket 2 design.
     * Keyed by room_type_id, not trip_instance_id: a trip's seat capacity and its room capacity
     * are independent concerns that can each fail/succeed on their own.
     */
    public function up(): void
    {
        Schema::create('room_inventory_ledgers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->integer('quantity');
            $table->enum('type', ['initial_stock', 'hold', 'confirmed', 'cancelled', 'expired']);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_inventory_ledgers');
    }
};
