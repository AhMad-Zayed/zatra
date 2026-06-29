<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ReleaseWaitlistHold implements ShouldQueue
{
    use Queueable;

    protected $holdId;
    protected $waitlistId;

    /**
     * Create a new job instance.
     */
    public function __construct($holdId, $waitlistId)
    {
        $this->holdId = $holdId;
        $this->waitlistId = $waitlistId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $hold = \App\Models\InventoryLedger::find($this->holdId);
        $waitlist = \App\Models\WaitingList::find($this->waitlistId);

        if ($hold && $hold->type === 'hold') {
            // It expired. Release it by expiring or deleting it.
            $hold->update([
                'type' => 'expired',
            ]);

            if ($waitlist) {
                $waitlist->update(['status' => \App\Enums\WaitingListStatusEnum::Expired]);
            }

            // Trigger promotion for next in line
            if ($hold->trip_instance_id) {
                \App\Jobs\WaitlistAutoPromotion::dispatch($hold->trip_instance_id);
            }

            \App\Models\AutomationRun::create([
                'job_name' => 'ReleaseWaitlistHold',
                'last_run_at' => now(),
                'records_processed' => 1,
                'status' => 'success',
            ]);
        }
    }
}
