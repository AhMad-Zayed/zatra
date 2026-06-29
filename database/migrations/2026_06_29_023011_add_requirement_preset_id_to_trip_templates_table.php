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
        Schema::table('trip_templates', function (Blueprint $table) {
            $table->foreignId('requirement_preset_id')->nullable()->constrained('requirement_presets')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trip_templates', function (Blueprint $table) {
            $table->dropForeign(['requirement_preset_id']);
            $table->dropColumn('requirement_preset_id');
        });
    }
};
