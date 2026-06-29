<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class AbandonedCartRecovery implements ShouldQueue
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
        // Find guest sessions that are older than 30 minutes, not expired (optional, or just past 30 min),
        // and have not created a booking (meaning hold_id is still a hold, not confirmed).
        // Wait, hold becomes confirmed and gets a booking_id. So if hold->type == 'hold', it's abandoned.
        $sessions = \App\Models\GuestSession::where('created_at', '<=', now()->subMinutes(30))
            ->whereHas('hold', function ($query) {
                $query->where('type', 'hold'); // Not converted to confirmed
            })
            ->get();

        $processed = 0;
        foreach ($sessions as $session) {
            // Check if already notified
            $alreadyNotified = \App\Models\NotificationLog::where('type', 'AbandonedCart')
                ->where('related_id', $session->id)
                ->exists();

            if (!$alreadyNotified) {
                // Mock WhatsApp send
                \Illuminate\Support\Facades\Log::info("WhatsApp: Hi {$session->first_name}, you left items in your cart! Resume booking.");
                
                \App\Models\NotificationLog::create([
                    'type' => 'AbandonedCart',
                    'recipient_contact' => $session->phone ?? $session->email,
                    'related_id' => $session->id,
                ]);
                $processed++;
            }
        }

        \App\Models\AutomationRun::create([
            'job_name' => 'AbandonedCartRecovery',
            'last_run_at' => now(),
            'records_processed' => $processed,
            'status' => 'success',
        ]);
    }
}
