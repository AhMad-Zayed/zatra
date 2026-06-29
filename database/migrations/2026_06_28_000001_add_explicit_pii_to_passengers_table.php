<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('passengers', function (Blueprint $table) {
            $table->string('first_name')->after('trip_passenger_category_id')->nullable();
            $table->string('last_name')->after('first_name')->nullable();
            $table->string('document_type')->after('last_name')->nullable();
            $table->string('document_number')->after('document_type')->nullable()->index();
            $table->date('date_of_birth')->after('document_number')->nullable();
            $table->string('gender')->after('date_of_birth')->nullable();
            $table->renameColumn('dynamic_data', 'extra_preferences');
        });
    }

    public function down(): void
    {
        Schema::table('passengers', function (Blueprint $table) {
            $table->renameColumn('extra_preferences', 'dynamic_data');
            $table->dropColumn([
                'first_name',
                'last_name',
                'document_type',
                'document_number',
                'date_of_birth',
                'gender',
            ]);
        });
    }
};
