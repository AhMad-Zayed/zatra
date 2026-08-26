<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A single, addressable physical room slot within a RoomType (Room 1, Room 2, ... up to
     * RoomType.room_count) — the actual drag-and-drop target in the rooming UI. Deliberately NOT
     * pre-generated when a RoomType is created (that would mean touching Ticket 1/2's own CRUD/
     * observers); RoomAssignmentService::ensureRoomInstancesExist() lazily firstOrCreate()s these
     * the first time the assignment board opens for a given hotel option. Shared across all
     * bookings that selected that room type — matches how a physical hotel room actually works.
     * Hotel/Rooming redesign Ticket 3.
     */
    public function up(): void
    {
        Schema::create('room_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_type_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('room_number');
            $table->timestamps();

            $table->unique(['room_type_id', 'room_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_instances');
    }
};
