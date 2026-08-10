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
            $table->string('currency', 3)->default('USD')->after('title');
        });

        Schema::table('trip_instances', function (Blueprint $table) {
            $table->string('currency', 3)->default('USD')->after('start_date');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->string('currency', 3)->default('USD')->after('pnr');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->string('currency', 3)->default('USD')->after('amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trip_templates', function (Blueprint $table) {
            $table->dropColumn('currency');
        });

        Schema::table('trip_instances', function (Blueprint $table) {
            $table->dropColumn('currency');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('currency');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
};
