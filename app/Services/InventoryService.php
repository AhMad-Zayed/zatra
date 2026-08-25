<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\InventoryLedger;
use App\Models\TripInstance;
use App\Exceptions\InsufficientSeatsException;
use Illuminate\Support\Facades\DB;

class InventoryService
{
    /**
     * Consume inventory for a new booking, optionally releasing an existing hold.
     * Ensures exactly one confirmed ledger entry is created for the booking.
     */
    public function consumeForBooking(Booking $booking, int $seats, ?InventoryLedger $hold = null): void
    {
        if ($seats <= 0) return;

        $trip = $booking->tripInstance;

        // Release hold if provided
        if ($hold) {
            InventoryLedger::create([
                'tenant_id' => $hold->tenant_id,
                'trip_instance_id' => $hold->trip_instance_id,
                'booking_id' => $booking->id,
                'quantity' => abs($hold->quantity), // Positive to offset negative hold
                'type' => 'hold',
                'notes' => "Releasing hold {$hold->id} for booking {$booking->pnr}",
            ]);
        }

        if ($trip->available_seats !== null) {
            $consumed = InventoryLedger::where('trip_instance_id', $trip->id)
                ->where(function ($q) {
                    $q->whereNull('expires_at')
                      ->orWhere('expires_at', '>', now());
                })->sum('quantity');

            // $consumed is typically negative
            $remaining = $trip->available_seats + $consumed;
            
            if ($remaining < $seats) {
                throw new InsufficientSeatsException("لا توجد مقاعد كافية. المقاعد المتاحة: " . max(0, $remaining));
            }
        }

        // Create exactly one confirmed entry
        InventoryLedger::create([
            'tenant_id' => $booking->tenant_id,
            'trip_instance_id' => $booking->trip_instance_id,
            'booking_id' => $booking->id,
            'quantity' => -$seats,
            'type' => 'confirmed',
            'notes' => 'Booking confirmation',
        ]);
    }

    /**
     * Release inventory for a cancelled booking.
     * Idempotent: safe to call multiple times.
     *
     * P0-5 note (deferred, not part of this phase): this idempotency check treats *any*
     * existing type='cancelled' row for the booking as "already released", but
     * adjustForPassengerChange() below also legitimately writes type='cancelled' rows for
     * partial passenger removals (cancelPassengers() on a subset of passengers) — a booking
     * can accumulate several of these over its lifetime, which is normal, not a duplicate.
     * That means a later full-booking cancellation on a booking that already had a partial
     * passenger cancellation will see alreadyReleased=true here and skip, potentially
     * under-releasing the seats still held by the passengers cancelled in that final call.
     * Fixing this properly needs a release event distinguishable from a plain 'cancelled'
     * ledger type (which the existing P0-7 regression test asserts on literally), so it's
     * left as a future architectural item rather than resolved here. The concurrency race
     * P0-5 is responsible for (two overlapping calls double-releasing/double-notifying for
     * the *same* cancellation) is fully closed by BookingService::cancelBooking()'s
     * lockForUpdate() + already-cancelled early-return guard, independent of this method.
     */
    public function releaseForCancellation(Booking $booking): void
    {
        // Check if already cancelled
        $alreadyReleased = InventoryLedger::where('booking_id', $booking->id)
            ->where('type', 'cancelled')
            ->exists();

        if ($alreadyReleased) {
            return;
        }

        // Calculate net consumed by this booking (usually negative)
        $netConsumed = InventoryLedger::where('booking_id', $booking->id)
            ->where('type', '!=', 'hold') // Hold releases just cancel holds. 
            // Wait, summing ALL entries for this booking is the true net consumption.
            // If hold = -2, hold_release = +2, confirmed = -2. Sum = -2.
            ->sum('quantity');

        if ($netConsumed < 0) {
            InventoryLedger::create([
                'tenant_id' => $booking->tenant_id,
                'trip_instance_id' => $booking->trip_instance_id,
                'booking_id' => $booking->id,
                'quantity' => abs($netConsumed),
                'type' => 'cancelled',
                'notes' => 'Cancellation release for booking ' . $booking->pnr,
            ]);
        }
    }

    /**
     * Adjust inventory when a passenger is added or removed post-booking.
     */
    public function adjustForPassengerChange(Booking $booking, int $seatDifference): void
    {
        if ($seatDifference === 0) return;

        // If seatDifference > 0 (passenger added), we consume inventory (-quantity)
        // If seatDifference < 0 (passenger removed), we release inventory (+quantity)

        if ($seatDifference > 0) {
            $trip = $booking->tripInstance;
            if ($trip->available_seats !== null) {
                $consumed = InventoryLedger::where('trip_instance_id', $trip->id)
                    ->where(function ($q) {
                        $q->whereNull('expires_at')
                          ->orWhere('expires_at', '>', now());
                    })->sum('quantity');

                $remaining = $trip->available_seats + $consumed;
                
                if ($remaining < $seatDifference) {
                    throw new InsufficientSeatsException("لا توجد مقاعد كافية. المقاعد المتاحة: " . max(0, $remaining));
                }
            }
        }

        $type = $seatDifference > 0 ? 'confirmed' : 'cancelled';

        InventoryLedger::create([
            'tenant_id' => $booking->tenant_id,
            'trip_instance_id' => $booking->trip_instance_id,
            'booking_id' => $booking->id,
            'quantity' => -$seatDifference,
            'type' => $type,
            'notes' => 'Post-booking passenger adjustment',
        ]);
    }

    /**
     * Move a booking's seat consumption from one trip instance to another: release on the
     * source trip, consume on the destination trip. Caller (BookingService::transferBooking())
     * is responsible for locking both the booking row and the destination TripInstance row
     * before calling this, so the capacity check below reads a consistent, race-safe snapshot.
     *
     * Uses the same valid enum literals as every other InventoryService method ('cancelled' /
     * 'confirmed') — this replaces the two hand-rolled InventoryLedger::create() call pairs
     * previously duplicated in BookingResource.php and ViewBooking.php, one of which used the
     * invalid literal 'cancellation' (not a member of the inventory_ledgers.type DB enum).
     */
    public function transferSeats(Booking $booking, int $oldTripInstanceId, TripInstance $newTrip, int $seats): void
    {
        if ($seats <= 0) return;

        if ($newTrip->available_seats !== null) {
            $consumed = InventoryLedger::where('trip_instance_id', $newTrip->id)
                ->where(function ($q) {
                    $q->whereNull('expires_at')
                      ->orWhere('expires_at', '>', now());
                })->sum('quantity');

            $remaining = $newTrip->available_seats + $consumed;

            if ($remaining < $seats) {
                throw new InsufficientSeatsException("لا توجد مقاعد كافية في الرحلة الجديدة. المقاعد المتاحة: " . max(0, $remaining));
            }
        }

        InventoryLedger::create([
            'tenant_id' => $booking->tenant_id,
            'trip_instance_id' => $oldTripInstanceId,
            'booking_id' => $booking->id,
            'quantity' => $seats,
            'type' => 'cancelled',
            'notes' => "Transfer out to trip instance {$newTrip->id} for booking {$booking->pnr}",
        ]);

        InventoryLedger::create([
            'tenant_id' => $booking->tenant_id,
            'trip_instance_id' => $newTrip->id,
            'booking_id' => $booking->id,
            'quantity' => -$seats,
            'type' => 'confirmed',
            'notes' => "Transfer in from trip instance {$oldTripInstanceId} for booking {$booking->pnr}",
        ]);
    }
}
