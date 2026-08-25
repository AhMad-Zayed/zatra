<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mirrors the existing guest_sessions.hold_id pattern: lets a WaitingList row point at the
     * InventoryLedger hold created for it (by WaitlistAutoPromotion or the admin send_link_now
     * action), so a customer redeeming the offer link can reuse/release that exact hold instead
     * of a second, independent one being opened for them.
     */
    public function up(): void
    {
        Schema::table('waiting_lists', function (Blueprint $table) {
            $table->unsignedBigInteger('hold_id')->nullable()->after('notified_at');
        });
    }

    public function down(): void
    {
        Schema::table('waiting_lists', function (Blueprint $table) {
            $table->dropColumn('hold_id');
        });
    }
};
