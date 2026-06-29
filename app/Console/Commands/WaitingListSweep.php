<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\WaitingList;
use App\Enums\WaitingListStatusEnum;
use App\Jobs\ProcessWaitingListJob;
use Carbon\Carbon;

class WaitingListSweep extends Command
{
    protected $signature = 'waitinglist:sweep';
    protected $description = 'Sweep expired waiting list notifications and dispatch the next in queue';

    public function handle()
    {
        // Find notified records older than 2 hours
        $expiredRecords = WaitingList::where('status', WaitingListStatusEnum::Notified)
            ->where('notified_at', '<=', Carbon::now()->subHours(2))
            ->get();

        foreach ($expiredRecords as $record) {
            // Mark as expired
            $record->update([
                'status' => WaitingListStatusEnum::Expired,
            ]);

            // Dispatch job to notify the next person in line for this specific trip
            ProcessWaitingListJob::dispatch($record->tripInstance);
            
            $this->info("Expired WaitingList ID: {$record->id} and dispatched next.");
        }

        $this->info("Sweeping complete. Processed: " . $expiredRecords->count());
    }
}
