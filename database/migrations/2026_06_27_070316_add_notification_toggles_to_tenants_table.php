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
            $table->boolean('enable_email_alerts')->default(true)->after('cash_booking_expiry_hours');
            $table->boolean('enable_whatsapp_alerts')->default(true)->after('enable_email_alerts');
            $table->boolean('enable_sms_alerts')->default(true)->after('enable_whatsapp_alerts');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['enable_email_alerts', 'enable_whatsapp_alerts', 'enable_sms_alerts']);
        });
    }
};
