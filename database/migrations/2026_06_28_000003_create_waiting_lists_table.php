<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waiting_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trip_instance_id')->constrained()->cascadeOnDelete();
            $table->string('customer_name');
            $table->string('phone_number');
            $table->string('customer_email')->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
            
            $table->index(['trip_instance_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waiting_lists');
    }
};
