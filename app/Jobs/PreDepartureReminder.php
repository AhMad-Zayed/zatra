<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PreDepartureReminder implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Find trips departing tomorrow
        $trips = \App\Models\TripInstance::whereDate('start_date', now()->addDay()->toDateString())->get();

        $processed = 0;
        foreach ($trips as $trip) {
            $bookings = $trip->bookings()
                ->whereIn('booking_status', [\App\Enums\BookingStatus::Confirmed, \App\Enums\BookingStatus::ConfirmedPartial])
                ->get();

            foreach ($bookings as $booking) {
                // Check if already notified
                $alreadyNotified = \App\Models\NotificationLog::where('type', 'PreDeparture')
                    ->where('related_id', $booking->id)
                    ->exists();

                if (!$alreadyNotified) {
                    $balanceDue = max(0, $booking->grand_total - $booking->total_paid);
                    // Mock WhatsApp send
                    \Illuminate\Support\Facades\Log::info("WhatsApp: Reminder for {$booking->customer->name}, trip tomorrow! Balance due: {$balanceDue}.");
                    
                    \App\Models\NotificationLog::create([
                        'type' => 'PreDeparture',
                        'recipient_contact' => $booking->customer->phone ?? $booking->customer->email,
                        'related_id' => $booking->id,
                    ]);
                    $processed++;
                }
            }
        }

        \App\Models\AutomationRun::create([
            'job_name' => 'PreDepartureReminder',
            'last_run_at' => now(),
            'records_processed' => $processed,
            'status' => 'success',
        ]);
    }
}
