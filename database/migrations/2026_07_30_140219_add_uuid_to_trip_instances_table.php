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
        Schema::table('trip_instances', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->after('id')->unique();
        });
        
        // Populate existing records with UUIDs
        \Illuminate\Support\Facades\DB::table('trip_instances')->whereNull('uuid')->chunkById(100, function ($trips) {
            foreach ($trips as $trip) {
                \Illuminate\Support\Facades\DB::table('trip_instances')
                    ->where('id', $trip->id)
                    ->update(['uuid' => (string) \Illuminate\Support\Str::uuid()]);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trip_instances', function (Blueprint $table) {
            $table->dropColumn('uuid');
        });
    }
};
