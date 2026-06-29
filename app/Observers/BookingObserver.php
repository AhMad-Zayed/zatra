<?php

namespace App\Observers;

use App\Models\Booking;
use App\Enums\BookingStatus;
use App\Notifications\BookingPending;

class BookingObserver
{
    /**
     * Handle the Booking "created" event.
     */
    public function created(Booking $booking): void
    {
        // When a booking is created, notify the customer if it's pending
        if ($booking->booking_status === \App\Enums\BookingStatus::Pending) {
            $booking->customer->notify(new BookingPending($booking));
        }
    }

    public function updated(Booking $booking): void
    {
        if ($booking->wasChanged('booking_status') && $booking->booking_status === BookingStatus::Cancelled) {
            \App\Jobs\WaitlistAutoPromotion::dispatch($booking->trip_instance_id);
        }
    }
}
