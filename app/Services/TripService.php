<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\TripStatusEnum;
use App\Models\Booking;
use App\Models\TripInstance;
use Illuminate\Support\Facades\DB;

class TripService
{
    public function __construct(
        protected BookingService $bookingService
    ) {}

    /**
     * Cancel a TripInstance and cascade-cancel all active bookings.
     *
     * - Blocks entirely on Completed or InProgress trips (no exceptions, no emergency path —
     *   a distinct "Aborted" status for InProgress emergency stops is a separate, future
     *   ticket, not handled here).
     * - Sets trip status to 'cancelled'
     * - For every non-cancelled booking on this trip:
     *     1. Releases inventory (seats + PackageOption seats)
     *     2. Logs activity
     *     3. Notifies the customer (queued)
     *     4. Triggers WaitlistAutoPromotion
     *     5. Tracks refund liability via payment_status (RefundPending), never by modifying
     *        grand_total/total_paid/balance_due
     *
     * @throws \RuntimeException if the trip is Completed or InProgress.
     * @throws \Throwable on any DB/service error — the outer transaction rolls back everything.
     */
    public function cancelTrip(TripInstance $trip, string $reason = ''): int
    {
        $cancelledCount = 0;

        DB::transaction(function () use ($trip, $reason, &$cancelledCount) {
            // Lock and re-read fresh before deciding anything, matching the same
            // lock-then-recheck discipline cancelBooking()/transferBooking() already use.
            $locked = TripInstance::where('id', $trip->id)->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, [TripStatusEnum::Completed, TripStatusEnum::InProgress], true)) {
                throw new \RuntimeException('لا يمكن إلغاء رحلة مكتملة أو قيد التنفيذ.');
            }

            if ($locked->status === TripStatusEnum::Cancelled) {
                // Idempotent no-op, same shape as cancelBooking()'s already-cancelled guard.
                return;
            }

            // 1. Mark the trip as cancelled
            DB::table('trip_instances')
                ->where('id', $trip->id)
                ->update(['status' => 'cancelled']);

            // 2. Fetch every booking that is NOT already cancelled
            $bookings = Booking::where('trip_instance_id', $trip->id)
                ->whereNotIn('booking_status', [BookingStatus::Cancelled->value])
                ->with([
                    'customer',
                    'packageOption',
                    'bookingAddons.tripAddon',
                    'passengers.tripPassengerCategory',
                ])
                ->lockForUpdate()
                ->get();

            // 3. Cancel each booking via the shared BookingService
            foreach ($bookings as $booking) {
                $this->bookingService->cancelBooking(
                    $booking,
                    $reason ?: "إلغاء الرحلة #{$trip->id}"
                );
                $cancelledCount++;
            }
        });

        return $cancelledCount;
    }
}
