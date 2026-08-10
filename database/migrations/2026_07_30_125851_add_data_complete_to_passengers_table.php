<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phone Booking Mode: passengers can be created as "seat holders" with no personal details yet.
     * data_complete = false means the customer still needs to fill in their info via the self-service link.
     */
    public function up(): void
    {
        Schema::table('passengers', function (Blueprint $table) {
            // false = seat reserved but personal data not yet collected (phone booking mode)
            $table->boolean('data_complete')->default(true)->after('extra_preferences');
            // Human-readable label when actual name unknown, e.g. "راكب 1 (بالغ)"
            $table->string('passenger_label')->nullable()->after('data_complete');
        });
    }

    public function down(): void
    {
        Schema::table('passengers', function (Blueprint $table) {
            $table->dropColumn(['data_complete', 'passenger_label']);
        });
    }
};
