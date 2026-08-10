<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('passenger_categories', function (Blueprint $table) {
            $table->boolean('requires_seat')->default(true)->after('default_price');
        });

        Schema::table('template_passenger_categories', function (Blueprint $table) {
            $table->boolean('requires_seat')->default(true)->after('price');
        });

        Schema::table('trip_passenger_categories', function (Blueprint $table) {
            $table->boolean('requires_seat')->default(true)->after('price');
        });
    }

    public function down(): void
    {
        Schema::table('passenger_categories', function (Blueprint $table) {
            $table->dropColumn('requires_seat');
        });

        Schema::table('template_passenger_categories', function (Blueprint $table) {
            $table->dropColumn('requires_seat');
        });

        Schema::table('trip_passenger_categories', function (Blueprint $table) {
            $table->dropColumn('requires_seat');
        });
    }
};
