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
            // It expired. Release it using DB::table() to bypass the immutability observer
            // (intentional internal operation — not an external mutation)
            \Illuminate\Support\Facades\DB::table('inventory_ledgers')
                ->where('id', $hold->id)
                ->where('type', 'hold') // safety: only expire actual holds
                ->update(['type' => 'expired']);

            // Bug fix: this used to unconditionally overwrite status to Expired, even when the
            // customer had already successfully redeemed this exact hold and converted it into
            // a booking (status already Converted) — an admin could watch a successful
            // conversion silently revert to "Expired" in the waiting-list table hours later.
            if ($waitlist && $waitlist->status !== \App\Enums\WaitingListStatusEnum::Converted) {
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
