<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pre-existing, unrelated bug discovered while implementing Hotel/Rooming Ticket 2's
     * kill-switch: `tenants.settings` is read/written extensively throughout the app
     * (StripeGateway's tenant-specific gateway keys, PackageOptionsRelationManager's per-tenant
     * room-type/meal-plan lists, WaitlistAutoPromotion's channel preference,
     * ManageAgencySettings' entire contact-info/FAQ/social-links page) and is declared on the
     * Tenant model's $fillable/$casts — but no migration ever actually created the column. Every
     * one of those features has been silently reading `?? default` fallbacks and/or failing to
     * persist writes. Adding it here because Ticket 2's kill-switch
     * (settings['room_booking_enabled']) cannot function at all without it — this is the
     * minimum necessary fix to make the approved Ticket 2 design work, not unrelated scope.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->json('settings')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('settings');
        });
    }
};
