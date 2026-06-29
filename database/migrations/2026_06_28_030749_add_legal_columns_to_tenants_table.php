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
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('tourism_license_number')->nullable()->after('domain');
            $table->longText('terms_conditions')->nullable();
            $table->longText('privacy_policy')->nullable();
            $table->longText('refund_policy')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'tourism_license_number',
                'terms_conditions',
                'privacy_policy',
                'refund_policy'
            ]);
        });
    }
};
