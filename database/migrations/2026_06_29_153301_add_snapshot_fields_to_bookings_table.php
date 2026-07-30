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
            $table->string('snapshot_trip_title')->nullable()->after('notes');
            $table->string('snapshot_template_name')->nullable()->after('snapshot_trip_title');
            $table->date('snapshot_start_date')->nullable()->after('snapshot_template_name');
            $table->date('snapshot_end_date')->nullable()->after('snapshot_start_date');
            $table->string('snapshot_currency', 3)->nullable()->after('snapshot_end_date');
            $table->decimal('snapshot_total_price', 10, 2)->nullable()->after('snapshot_currency');
            $table->decimal('snapshot_taxes', 10, 2)->nullable()->after('snapshot_total_price');
            $table->decimal('snapshot_discounts', 10, 2)->nullable()->after('snapshot_taxes');
            $table->json('snapshot_passenger_rules')->nullable()->after('snapshot_discounts');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn([
                'snapshot_trip_title',
                'snapshot_template_name',
                'snapshot_start_date',
                'snapshot_end_date',
                'snapshot_currency',
                'snapshot_total_price',
                'snapshot_taxes',
                'snapshot_discounts',
                'snapshot_passenger_rules',
            ]);
        });
    }
};
