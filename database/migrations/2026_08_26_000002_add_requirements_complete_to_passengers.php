<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Distinct from the existing `data_complete` (which only tracks whether a phone-booking
     * placeholder passenger has had their name/basic info filled in). This tracks whether a
     * passenger satisfies their trip's attached RequirementPreset specifically — a passenger can
     * have data_complete = true and requirements_complete = false (e.g. name/document number
     * filled in, but a required passport photo still outstanding).
     *
     * Defaults to true so existing passenger rows (created before this feature existed) are not
     * retroactively flagged as incomplete — there is no way to know their real status, and the
     * point of this field is forward-looking visibility, not a historical audit.
     */
    public function up(): void
    {
        Schema::table('passengers', function (Blueprint $table) {
            $table->boolean('requirements_complete')->default(true)->after('data_complete');
        });
    }

    public function down(): void
    {
        Schema::table('passengers', function (Blueprint $table) {
            $table->dropColumn('requirements_complete');
        });
    }
};
