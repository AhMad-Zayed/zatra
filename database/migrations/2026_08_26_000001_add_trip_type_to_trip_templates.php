<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pure classification/filtering/reporting field — deliberately nullable with no default.
     * Every existing template is currently unclassified; defaulting to a guessed value would
     * silently mislabel real data. Must never drive automatic validation or business logic
     * (no auto-requiring documents, no auto-toggling hotel/package steps based on this alone).
     */
    public function up(): void
    {
        Schema::table('trip_templates', function (Blueprint $table) {
            $table->string('trip_type')->nullable()->after('currency');
        });
    }

    public function down(): void
    {
        Schema::table('trip_templates', function (Blueprint $table) {
            $table->dropColumn('trip_type');
        });
    }
};
