<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PostTripReviewRequest implements ShouldQueue
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
        // Find trips that ended yesterday
        $trips = \App\Models\TripInstance::whereDate('end_date', '<=', now()->subDay()->toDateString())->get();

        $processed = 0;
        foreach ($trips as $trip) {
            $bookings = $trip->bookings()
                ->where('review_requested', false)
                ->whereIn('booking_status', [\App\Enums\BookingStatus::Confirmed, \App\Enums\BookingStatus::ConfirmedPartial])
                ->get();

            foreach ($bookings as $booking) {
                // Mock WhatsApp send
                \Illuminate\Support\Facades\Log::info("WhatsApp: Hi {$booking->customer->name}, how was your trip? Please leave a review!");
                
                $booking->update(['review_requested' => true]);
                
                \App\Models\NotificationLog::create([
                    'type' => 'PostTripReview',
                    'recipient_contact' => $booking->customer->phone ?? $booking->customer->email,
                    'related_id' => $booking->id,
                ]);
                $processed++;
            }
        }

        \App\Models\AutomationRun::create([
            'job_name' => 'PostTripReviewRequest',
            'last_run_at' => now(),
            'records_processed' => $processed,
            'status' => 'success',
        ]);
    }
}
