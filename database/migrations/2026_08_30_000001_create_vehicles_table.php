<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bus/Fleet redesign Ticket 1 — reusable master entity for COMPANY-OWNED buses only
     * (same physical vehicle reused across many trips, mirroring Hotel's role in the
     * Hotel/Rooming redesign). Rented buses are NOT modeled here — they're a different physical
     * vehicle nearly every time, captured inline on trip_bus_assignments instead (see that
     * migration's docblock for the full reasoning).
     */
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('plate_number');
            $table->unsignedInteger('default_capacity');
            $table->foreignId('default_driver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};
