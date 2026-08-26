<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Price Integrity Audit, Finding A: BookingService::recalculateTotals() read
     * $booking->packageOption->price_adjustment LIVE instead of a stored snapshot, corrupting
     * the stored grand_total column on nearly every subsequent booking mutation once a
     * PackageOption's price changed after booking (confirmed live: 150 -> 600 after a live price
     * change + one new payment). package_price_at_booking is the fix -- same int-cents,
     * MoneyCast-backed convention as price_at_booking on Passenger/BookingAddon/
     * BookingRoomSelection, storing the PER-PASSENGER adjustment (matching
     * PackageOption.price_adjustment's own per-unit semantic), not pre-multiplied by passenger
     * count -- recalculateTotals() still multiplies by the CURRENT passenger count each time, so
     * the charge still scales correctly if passengers are added/removed later; only the
     * per-unit price itself is frozen.
     *
     * Backfill for existing bookings, reasoned explicitly rather than picked silently: copying
     * the CURRENT live PackageOption.price_adjustment into existing bookings was considered and
     * rejected -- it's only a coincidental match if that price never changed since booking, no
     * better a guess than what recalculateTotals() was already (incorrectly) doing. Instead,
     * back-derive the historical per-unit adjustment ARITHMETICALLY from each booking's OWN
     * already-stored, already-correct-until-now grand_total (solving recalculateTotals()'s own
     * total formula for the package term): this is exact, not an approximation, PROVIDED the
     * booking's grand_total hasn't already been corrupted by a prior live-price drift before
     * this fix deploys. If it already had (undetectable from current data alone -- Finding E
     * confirmed no audit trail exists to recover the true original value), this still does the
     * best available thing: freezes whatever the CURRENT (possibly already-wrong) total implies
     * from this point forward, stopping any FURTHER drift, rather than leaving the column null
     * (which would silently zero out the entire package charge on the next recalculation --
     * a NEW, different corruption, strictly worse than freezing the status quo).
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->integer('package_price_at_booking')->nullable()->after('package_option_id');
        });

        $bookings = DB::table('bookings')
            ->whereNotNull('package_option_id')
            ->get(['id', 'grand_total', 'discount_amount']);

        foreach ($bookings as $booking) {
            $passengerSum = (int) DB::table('passengers')
                ->where('booking_id', $booking->id)
                ->whereNull('deleted_at')
                ->sum('price_at_booking');

            $addonSum = (int) DB::table('booking_addons')
                ->where('booking_id', $booking->id)
                ->whereNull('deleted_at')
                ->selectRaw('COALESCE(SUM(price_at_booking * quantity), 0) as total')
                ->value('total');

            $roomSum = (int) DB::table('booking_room_selections')
                ->where('booking_id', $booking->id)
                ->whereNull('deleted_at')
                ->sum('price_at_booking');

            $passengerCount = (int) DB::table('passengers')
                ->where('booking_id', $booking->id)
                ->whereNull('deleted_at')
                ->count();

            if ($passengerCount === 0) {
                // Nothing to divide by -- leave null, matches "no passengers, nothing to infer."
                continue;
            }

            // discount_amount's column is declared decimal(12,2), but -- confirmed via a real
            // round-trip through MoneyCast -- the raw stored value is ALREADY in cents (e.g.
            // 5000.00 for a $50.00 discount, not 50.00), matching grand_total's int-cents
            // convention despite the differing column type. No dollars-to-cents conversion
            // needed here; the ".00" fractional part is always zero.
            $discountCents = (int) $booking->discount_amount;

            $totalPackageAdjustment = ((int) $booking->grand_total) - $passengerSum - $addonSum - $roomSum + $discountCents;
            $perPassengerAdjustment = (int) round($totalPackageAdjustment / $passengerCount);

            DB::table('bookings')
                ->where('id', $booking->id)
                ->update(['package_price_at_booking' => $perPassengerAdjustment]);
        }
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('package_price_at_booking');
        });
    }
};
