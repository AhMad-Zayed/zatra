<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reports Center Ticket 1 (Phase 0, Section E) — passengers.requirements_complete drives
     * Report 1's primary filter; bookings.payment_status/balance_due drive Report 2's. Neither
     * had a dedicated index before (only implicit FK indexes existed). Not urgent at this app's
     * current data volume, but cheap to add now while building the reports that need them.
     */
    public function up(): void
    {
        Schema::table('passengers', function (Blueprint $table) {
            $table->index(['tenant_id', 'requirements_complete'], 'passengers_requirements_complete_idx');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->index(['tenant_id', 'payment_status'], 'bookings_payment_status_idx');
            $table->index(['tenant_id', 'balance_due'], 'bookings_balance_due_idx');
        });
    }

    public function down(): void
    {
        Schema::table('passengers', function (Blueprint $table) {
            $table->dropIndex('passengers_requirements_complete_idx');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex('bookings_payment_status_idx');
            $table->dropIndex('bookings_balance_due_idx');
        });
    }
};
