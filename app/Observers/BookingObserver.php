<?php

namespace App\Observers;

use App\Models\Booking;
use App\Notifications\BookingPending;

class BookingObserver
{
    /**
     * Handle the Booking "created" event.
     */
    public function created(Booking $booking): void
    {
        if ($booking->booking_status === \App\Enums\BookingStatus::Pending && $booking->customer) {
            $booking->customer->notify(new BookingPending($booking));
        }
    }

    // The cancellation branch that used to live here (releasing inventory + dispatching
    // WaitlistAutoPromotion whenever booking_status changed to Cancelled via a normal Eloquent
    // save) was dead code: the only live cancellation path, BookingService::cancelBooking(),
    // deliberately writes the status change via a raw DB::table('bookings')->update(...) call
    // specifically to bypass this observer (it already performs both of those side effects
    // itself, explicitly, right where the status is set — see cancelBooking()'s own comments).
    // No other code path sets booking_status to Cancelled via Eloquent, so this branch could
    // never fire in production; removed rather than left as a misleading trap for a future
    // maintainer who might assume cancellation side effects "just happen via the observer."
}
