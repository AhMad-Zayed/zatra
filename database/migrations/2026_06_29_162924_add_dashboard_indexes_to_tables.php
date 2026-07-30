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
        Schema::table('bookings', function (Blueprint $table) {
            $table->index(['tenant_id', 'booking_status', 'created_at'], 'bookings_dashboard_idx');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->index(['tenant_id', 'created_at'], 'payments_dashboard_idx');
        });

        Schema::table('trip_instances', function (Blueprint $table) {
            $table->index(['tenant_id', 'status'], 'trips_dashboard_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex('bookings_dashboard_idx');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_dashboard_idx');
        });

        Schema::table('trip_instances', function (Blueprint $table) {
            $table->dropIndex('trips_dashboard_idx');
        });
    }
};
