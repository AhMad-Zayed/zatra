<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
     * already-stored grand_total (solving recalculateTotals()'s own total formula for the
     * package term): this is exact, PROVIDED the booking's grand_total was never actually
     * corrupted by the bug -- i.e. the PackageOption's live price never changed after this
     * booking existed, or it did but no recalculateTotals()-triggering event (payment, passenger
     * add/cancel, reopen) ever ran afterward.
     *
     * If corruption already DID happen before this fix landed, deriving from the current
     * (already-wrong) grand_total would extract and permanently lock in that corrupted value as
     * the new "authoritative" snapshot -- silently freezing the corruption instead of fixing it.
     * PackageOption has no LogsActivity / price-history mechanism at all (confirmed), so for any
     * booking where this already happened, the TRUE original per-passenger value is genuinely
     * unrecoverable from any data this migration can see -- not merely hard to find.
     *
     * Detected (not silently assumed clean) via a conservative heuristic: recalculateTotals()
     * always bumps bookings.updated_at even via updateQuietly() (which only suppresses model
     * events, not timestamps), so `package.updated_at > booking.created_at AND
     * booking.updated_at > package.updated_at` flags "this booking's total was touched again
     * after the package was modified post-booking" -- a necessary condition for the corruption.
     * This can over-flag (the package or booking may have been touched for an unrelated field),
     * but cannot under-flag a real case.
     *
     * A flagged booking still gets the SAME derive-and-freeze treatment as a clean one -- NOT
     * left null. Leaving it null would be worse, not safer: recalculateTotals() treats a null
     * package_price_at_booking as 0, so on the booking's next recalculation the package charge
     * would silently vanish from grand_total entirely -- a brand-new, different corruption layered
     * on top of a possibly-already-wrong value, actively changing money owed rather than freezing
     * it. Deriving and freezing the current value is still the least-bad available action either
     * way: correct if clean, and merely "stops drifting further" if not. What actually changes
     * for a flagged booking is visibility: logged loudly (booking id, tenant, package id, both
     * timestamps) so a human can review and manually correct that specific booking if warranted --
     * the original per-passenger price cannot be mechanically verified either way.
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->integer('package_price_at_booking')->nullable()->after('package_option_id');
        });

        $bookings = DB::table('bookings')
            ->whereNotNull('package_option_id')
            ->get(['id', 'tenant_id', 'package_option_id', 'grand_total', 'discount_amount', 'created_at', 'updated_at']);

        $packageUpdatedAtById = DB::table('package_options')
            ->whereIn('id', $bookings->pluck('package_option_id')->unique())
            ->pluck('updated_at', 'id');

        foreach ($bookings as $booking) {
            $packageUpdatedAt = $packageUpdatedAtById->get($booking->package_option_id);

            // Necessary (not sufficient) condition for corruption: the package was touched at
            // some point after this booking existed, AND the booking's own row was touched again
            // after that (recalculateTotals() always bumps bookings.updated_at, even via
            // updateQuietly()). See the class docblock for the full reasoning.
            $possiblyCorrupted = $packageUpdatedAt
                && $packageUpdatedAt > $booking->created_at
                && $booking->updated_at > $packageUpdatedAt;

            if ($possiblyCorrupted) {
                Log::warning(
                    'Price Integrity Audit backfill: booking shows signs of a possible pre-fix grand_total corruption (its PackageOption was modified after this booking was created, and the booking was recalculated again afterward). The derived package_price_at_booking below is the best available value -- it freezes the current total rather than leaving it to drift further -- but the true original per-passenger package price cannot be verified or recovered (PackageOption has no price-history record). Recommend manual review.',
                    [
                        'booking_id' => $booking->id,
                        'tenant_id' => $booking->tenant_id,
                        'package_option_id' => $booking->package_option_id,
                        'booking_created_at' => $booking->created_at,
                        'package_updated_at' => $packageUpdatedAt,
                        'booking_updated_at' => $booking->updated_at,
                    ]
                );
            }

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
