<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * $template->duration_days was already referenced on both trip-details.blade.php (the
 * zero-instances fallback in the header meta row) and the catalog card, but never existed as a
 * column, accessor, or any computed property -- it always silently evaluated to null. Confirmed
 * via the real seeded data that instances of the same template share the same length in practice
 * (Maldives Luxury Package: both real instances are 6 days), and the catalog card needs a
 * duration before any specific instance/date is even chosen -- so this is a template-level
 * "advertised" fact, not something to compute from a specific instance. Where a specific instance
 * *is* selected, trip-details' quick-info bar (added in the previous content-density ticket)
 * already computes the real, possibly-more-precise duration from that instance's own start/end
 * dates -- this column doesn't replace that, it only fixes the two places that had no data at
 * all before an instance is chosen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trip_templates', function (Blueprint $table) {
            $table->unsignedSmallInteger('duration_days')->nullable()->after('base_price');
        });
    }

    public function down(): void
    {
        Schema::table('trip_templates', function (Blueprint $table) {
            $table->dropColumn('duration_days');
        });
    }
};
