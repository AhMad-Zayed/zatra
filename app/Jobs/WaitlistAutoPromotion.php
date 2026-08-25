<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

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
        DB::transaction(function () {
            // Lock TripInstance to prevent race conditions
            $tripInstance = \App\Models\TripInstance::where('id', $this->tripInstanceId)
                ->lockForUpdate()
                ->first();
            
            if (!$tripInstance) return;

            // Lock the next waitlist entry too
            $nextWaitlist = \App\Models\WaitingList::whereHas('tripInstances', function ($q) {
                    $q->where('trip_instances.id', $this->tripInstanceId);
                })
                ->where('status', \App\Enums\WaitingListStatusEnum::Pending)
                ->orderBy('created_at', 'asc')
                ->lockForUpdate()
                ->first();

            if (!$nextWaitlist) return;

            // FIX: use correct column name seats_requested
            $seatsRequested = $nextWaitlist->seats_requested ?? 1;

            // Recalculate available inside transaction
            $ledgerSum = \App\Models\InventoryLedger::where('trip_instance_id', $this->tripInstanceId)
                ->where(function ($q) {
                    $q->whereNull('expires_at')
                      ->orWhere('expires_at', '>', now());
                })
                ->sum('quantity');
            
            $available = $tripInstance->available_seats + $ledgerSum;

            if ($available < $seatsRequested) {
                return; // Not enough seats, skip
            }

            // Create a 2-hour hold
            $hold = \App\Models\InventoryLedger::create([
                'trip_instance_id' => $this->tripInstanceId,
                'quantity'         => -$seatsRequested,
                'type'             => 'hold',
                'expires_at'       => now()->addHours(2),
            ]);

            // Dispatch release job
            \App\Jobs\ReleaseWaitlistHold::dispatch($hold->id, $nextWaitlist->id)
                ->delay(now()->addHours(2));

            // hold_id is persisted (mirroring guest_sessions.hold_id) so that a customer who
            // redeems this offer link reuses this exact hold via CheckoutWizard instead of a
            // second, independent one being opened for them.
            $nextWaitlist->update([
                'status' => \App\Enums\WaitingListStatusEnum::Notified,
                'hold_id' => $hold->id,
            ]);
            
            \Illuminate\Support\Facades\Log::info("Waitlist: {$nextWaitlist->customer_name} notified for trip {$this->tripInstanceId}.");
            
            \App\Models\NotificationLog::create([
                'type'              => 'WaitlistPromotion',
                'recipient_contact' => $nextWaitlist->phone_number,
                'related_id'        => $nextWaitlist->id,
            ]);

            \App\Models\AutomationRun::create([
                'job_name'         => 'WaitlistAutoPromotion',
                'last_run_at'      => now(),
                'records_processed' => 1,
                'status'           => 'success',
            ]);
        });
    }
}
