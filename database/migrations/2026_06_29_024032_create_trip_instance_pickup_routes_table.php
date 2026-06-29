<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('trip_instance_pickup_routes', function (Blueprint $table) {
            $table->foreignId('trip_instance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pickup_route_id')->constrained()->cascadeOnDelete();
            $table->primary(['trip_instance_id', 'pickup_route_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trip_instance_pickup_routes');
    }
};
