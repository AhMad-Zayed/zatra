<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('package_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('trip_instance_id')->constrained('trip_instances')->cascadeOnDelete();
            
            $table->string('name');
            $table->string('hotel_name')->nullable();
            $table->tinyInteger('stars')->nullable();
            $table->string('room_type')->nullable();
            $table->string('meal_plan')->nullable();
            
            $table->integer('price_adjustment')->default(0);
            $table->integer('available_seats')->nullable();
            
            $table->text('description')->nullable();
            $table->json('included_features')->nullable();
            
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_options');
    }
};
