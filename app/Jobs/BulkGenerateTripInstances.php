<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\TripTemplate;
use App\Models\TripInstance;
use App\Models\User;
use Carbon\Carbon;
use Filament\Notifications\Notification;

class BulkGenerateTripInstances implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300;

    protected $templateId;
    protected $dates;
    protected $seatsCount;
    protected $pickupRouteIds;
    protected $userId;

    /**
     * Create a new job instance.
     */
    public function __construct(int $templateId, array $dates, int $seatsCount, array $pickupRouteIds, int $userId)
    {
        $this->templateId = $templateId;
        $this->dates = $dates;
        $this->seatsCount = $seatsCount;
        $this->pickupRouteIds = $pickupRouteIds;
        $this->userId = $userId;
    }

    /**
     * Execute the job.
     *
     * Returns the created instances (widened from the original void return type) so a synchronous
     * caller (dispatchSync(), used by TripInstanceResource's ported recurring-schedule create
     * flow) can redirect to one of them — a purely additive change, since nothing previously read
     * this job's return value while it was only ever async-dispatched.
     *
     * @return \Illuminate\Support\Collection<int, TripInstance>
     */
    public function handle(): \Illuminate\Support\Collection
    {
        $template = TripTemplate::with(['templatePassengerCategories', 'templateAddons'])->find($this->templateId);
        if (!$template) return collect();

        $user = User::find($this->userId);
        $chunks = array_chunk($this->dates, 100);
        $totalCreated = 0;
        $createdInstances = collect();

        foreach ($chunks as $chunk) {
            foreach ($chunk as $dateString) {
                $date = Carbon::parse($dateString);
                
                $instance = TripInstance::create([
                    'tenant_id' => $template->tenant_id,
                    'trip_template_id' => $template->id,
                    'start_date' => $date,
                    'end_date' => $date, // Assuming 1-day trip for bulk generation or could be adjusted
                    'available_seats' => $this->seatsCount,
                    'status' => 'active',
                ]);

                // Create Tiers
                // tenant_id set explicitly, not left to TripPassengerCategory's own
                // Filament::getTenant()-fallback creating() hook -- that hook only resolves inside
                // an actual Filament panel request; this job (ShouldQueue) can run from a real
                // queue worker with no such context, which would otherwise crash on the
                // NOT NULL tenant_id column exactly the way it did when first tested outside an
                // active panel session. $instance->tenant_id (set explicitly two lines above) is
                // always reliable regardless of how/where this job runs.
                foreach ($template->templatePassengerCategories as $tier) {
                    $instance->tripPassengerCategories()->create([
                        'tenant_id' => $instance->tenant_id,
                        'name' => $tier->name,
                        'price' => $tier->price,
                        'requires_seat' => $tier->requires_seat,
                    ]);
                }

                // Create Addons
                foreach ($template->templateAddons as $addon) {
                    $instance->tripAddons()->create([
                        'tenant_id' => $instance->tenant_id,
                        'name' => $addon->name,
                        'price' => $addon->price,
                        'max_quantity' => $addon->max_quantity,
                    ]);
                }

                // Attach Pickup Routes
                if (!empty($this->pickupRouteIds)) {
                    $instance->pickupRoutes()->attach($this->pickupRouteIds);
                }

                $createdInstances->push($instance);
                $totalCreated++;
            }
        }

        if ($user) {
            Notification::make()
                ->title('تم إنشاء الرحلات المجدولة بنجاح')
                ->body("تم إنشاء {$totalCreated} موعداً للرحلة '{$template->title}'.")
                ->success()
                ->sendToDatabase($user);
        }

        return $createdInstances;
    }
}
