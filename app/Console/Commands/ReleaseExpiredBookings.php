<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Booking;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Services\BookingService;
use Illuminate\Support\Facades\DB;
use App\Jobs\SendBookingNotificationJob;

class ReleaseExpiredBookings extends Command
{
    protected $signature = 'bookings:release-expired';
    protected $description = 'Cancel pending unpaid bookings that have exceeded their expiry time and securely release inventory';

    public function handle()
    {
        $expiredBookings = Booking::where('booking_status', BookingStatus::Pending)
            ->where('payment_status', PaymentStatus::Unpaid)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->with('customer')
            ->get();

        $count = 0;
        $bookingService = app(BookingService::class);

        foreach ($expiredBookings as $expiredBooking) {
            DB::transaction(function () use ($expiredBooking, &$count, $bookingService) {
                // P0-5: lock the row and re-check its state under the lock before mutating.
                // The initial query above ran outside any transaction, so an overlapping run
                // of this same command (e.g. a slow previous run still in flight when the
                // next schedule tick fires) can select the same expired booking twice. The
                // first run to reach this lock processes it normally; the second blocks here
                // until the first commits, then sees booking_status is no longer Pending and
                // skips — preventing a duplicate cancellation and duplicate notifications.
                $booking = Booking::where('id', $expiredBooking->id)->lockForUpdate()->first();

                if (!$booking
                    || $booking->booking_status !== BookingStatus::Pending
                    || $booking->payment_status !== PaymentStatus::Unpaid
                ) {
                    return;
                }

                // Cancellation mechanics (status write, passenger cleanup, inventory release,
                // payment_status -> RefundPending when applicable, activity log entry, generic
                // BookingCancelled notification) now delegate to the single canonical authority
                // (P0-5/P0-6/P0-7) instead of a raw status update. cancelBooking() re-locks and
                // re-checks this same row internally — safe, same connection/transaction, no
                // deadlock — and is itself idempotent, but the lock+recheck above is still what
                // lets this command know it "won" processing this booking exactly once, which is
                // what the timeout-specific notification below relies on to avoid double-sending.
                $bookingService->cancelBooking($booking, 'انتهاء مهلة الدفع');

                $message = "مرحباً، نأسف لإبلاغك بأنه تم إلغاء حجزك المبدئي رقم {$booking->pnr} بسبب انتهاء مهلة الدفع المحددة. يمكنك إجراء حجز جديد عبر موقعنا.";

                // Dispatch WhatsApp Notification (Default) — kept as its own explicit step,
                // separate from cancelBooking()'s generic BookingCancelled notification, since
                // this message specifically explains *why* (payment timeout), which the generic
                // notification does not.
                if ($booking->customer && $booking->customer->phone) {
                    SendBookingNotificationJob::dispatch(
                        $booking,
                        'whatsapp',
                        $message
                    );
                }

                // Dispatch Email Notification (If email exists)
                if ($booking->customer && $booking->customer->email) {
                    SendBookingNotificationJob::dispatch(
                        $booking,
                        'email',
                        $message
                    );
                }

                $count++;
            });
        }

        $this->info("Successfully cancelled {$count} expired bookings and released their seats.");
    }
}
