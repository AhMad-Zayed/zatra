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
        Schema::table('trip_instance_waiting_list', function (Blueprint $table) {
            $table->integer('priority')->default(1)->after('waiting_list_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trip_instance_waiting_list', function (Blueprint $table) {
            $table->dropColumn('priority');
        });
    }
};
