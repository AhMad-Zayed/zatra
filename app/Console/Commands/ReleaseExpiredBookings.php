<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Booking;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

        foreach ($expiredBookings as $booking) {
            DB::transaction(function () use ($booking, &$count) {
                $booking->update([
                    'booking_status' => BookingStatus::Cancelled
                ]);
                
                // Log the inventory release for audit
                Log::info("Expired Booking {$booking->pnr} cancelled. Inventory automatically released via TripInstance getRemainingSeatsAttribute query constraint.");

                $message = "مرحباً، نأسف لإبلاغك بأنه تم إلغاء حجزك المبدئي رقم {$booking->pnr} بسبب انتهاء مهلة الدفع المحددة. يمكنك إجراء حجز جديد عبر موقعنا.";

                // Dispatch WhatsApp Notification (Default)
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
