<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class WaitlistAutoPromotion implements ShouldQueue
{
    use Queueable;

    protected $tripInstanceId;

    /**
     * Create a new job instance.
     */
    public function __construct($tripInstanceId)
    {
        $this->tripInstanceId = $tripInstanceId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $tripInstance = \App\Models\TripInstance::find($this->tripInstanceId);
        if (!$tripInstance) return;

        // Find next waitlisted person
        $nextWaitlist = \App\Models\WaitingList::where('trip_instance_id', $this->tripInstanceId)
            ->where('status', \App\Enums\WaitingListStatusEnum::Pending)
            ->orderBy('created_at', 'asc')
            ->first();

        if (!$nextWaitlist) return;

        // Check if there are available seats for their request
        $available = \App\Models\InventoryLedger::where('trip_instance_id', $this->tripInstanceId)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            })
            ->sum('quantity');

        if ($available >= $nextWaitlist->requested_seats) {
            // Create a 2-hour hold
            $hold = \App\Models\InventoryLedger::create([
                'trip_instance_id' => $this->tripInstanceId,
                'quantity' => -$nextWaitlist->requested_seats,
                'type' => 'hold',
                'expires_at' => now()->addHours(2),
            ]);

            // Dispatch release job
            \App\Jobs\ReleaseWaitlistHold::dispatch($hold->id, $nextWaitlist->id)
                ->delay(now()->addHours(2));

            // Notify customer
            $nextWaitlist->update(['status' => \App\Enums\WaitingListStatusEnum::Notified]);
            \Illuminate\Support\Facades\Log::info("WhatsApp: Hey {$nextWaitlist->customer->name}, seats opened up! You have 2 hours to book.");
            
            \App\Models\NotificationLog::create([
                'type' => 'WaitlistPromotion',
                'recipient_contact' => $nextWaitlist->customer->phone,
                'related_id' => $nextWaitlist->id,
            ]);

            \App\Models\AutomationRun::create([
                'job_name' => 'WaitlistAutoPromotion',
                'last_run_at' => now(),
                'records_processed' => 1,
                'status' => 'success',
            ]);
        }
    }
}
