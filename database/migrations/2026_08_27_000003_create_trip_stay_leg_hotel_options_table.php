<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The alternative hotel choice(s) available within a given leg (a leg can have 1 or several).
     * trip_stay_leg_id: tightly owned by its leg, cascades on delete (same convention as the
     * rest of this structural hierarchy). hotel_id: a REFERENCE to a shared, reusable master
     * record (not owned by this trip) — deliberately restrictOnDelete() rather than cascade, so
     * deleting/retiring a Hotel can never silently destroy live accommodation data on any trip
     * that still references it; the referencing option must be reassigned/removed first (the
     * HotelResource delete guard also blocks this at the application layer with a friendly
     * message, matching TripTemplateResource's existing "can't delete, has instances" pattern).
     * Hotel/Rooming redesign Phase 1.
     */
    public function up(): void
    {
        Schema::create('trip_stay_leg_hotel_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trip_stay_leg_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->string('label')->nullable();
            $table->string('meal_plan')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_stay_leg_hotel_options');
    }
};
