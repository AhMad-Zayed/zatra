<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class YieldPricingJob implements ShouldQueue
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
        // Find trips > 3 days away, no price override, not full but <= 5 seats
        $trips = \App\Models\TripInstance::whereDate('start_date', '>', now()->addDays(3)->toDateString())
            ->where('price_override', false)
            ->get();

        $processed = 0;
        foreach ($trips as $trip) {
            $available = $trip->available_seats;
            // available_seats might be dynamic or static. If dynamic we should calculate it.
            if ($available === null) continue;

            $booked = \App\Models\InventoryLedger::where('trip_instance_id', $trip->id)
                ->where(function ($q) {
                    $q->whereNull('expires_at')
                      ->orWhere('expires_at', '>', now());
                })
                ->sum('quantity');

            $seatsLeft = $available + $booked; // since booked is negative

            if ($seatsLeft > 0 && $seatsLeft <= 5) {
                // Apply 15% increase to base price. We calculate 15% of the cheapest tier to use as a flat override amount, or just set it to a fixed amount.
                $cheapestTier = \App\Models\TripPassengerCategory::where('trip_instance_id', $trip->id)->min('price');
                if ($cheapestTier) {
                    $increase = $cheapestTier * 0.15;
                    $trip->update([
                        'price_override' => true,
                        'price_override_amount' => $increase,
                    ]);
                    $processed++;
                }
            }
        }

        \App\Models\AutomationRun::create([
            'job_name' => 'YieldPricingJob',
            'last_run_at' => now(),
            'records_processed' => $processed,
            'status' => 'success',
        ]);
    }
}
