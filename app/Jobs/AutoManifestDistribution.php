<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class AutoManifestDistribution implements ShouldQueue
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
            // Check if already notified
            $alreadyNotified = \App\Models\NotificationLog::where('type', 'ManifestDistribution')
                ->where('related_id', $trip->id)
                ->exists();

            if (!$alreadyNotified) {
                // Mock sending manifest to guides
                \Illuminate\Support\Facades\Log::info("Email/WhatsApp: Sending manifest for Trip {$trip->id} to guides.");
                
                \App\Models\NotificationLog::create([
                    'type' => 'ManifestDistribution',
                    'recipient_contact' => 'guides@example.com',
                    'related_id' => $trip->id,
                ]);
                $processed++;
            }
        }

        \App\Models\AutomationRun::create([
            'job_name' => 'AutoManifestDistribution',
            'last_run_at' => now(),
            'records_processed' => $processed,
            'status' => 'success',
        ]);
    }
}
