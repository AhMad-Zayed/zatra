<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An ordered stage of a trip instance's accommodation (leg 1, leg 2, ...). A simple
     * single-hotel trip has exactly one leg. Tightly owned by its trip_instance — cascades on
     * delete, matching the same convention as trip_passenger_categories/trip_addons
     * (structural children of TripInstance that also cascadeOnDelete + softDeletes).
     * Hotel/Rooming redesign Phase 1.
     */
    public function up(): void
    {
        Schema::create('trip_stay_legs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trip_instance_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence')->default(1);
            $table->string('label')->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->softDeletes();
            $table->timestamps();

            $table->index(['trip_instance_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_stay_legs');
    }
};
