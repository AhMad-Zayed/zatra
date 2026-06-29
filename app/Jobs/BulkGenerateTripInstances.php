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
     */
    public function handle(): void
    {
        $template = TripTemplate::with(['templatePassengerCategories', 'templateAddons'])->find($this->templateId);
        if (!$template) return;

        $user = User::find($this->userId);
        $chunks = array_chunk($this->dates, 100);
        $totalCreated = 0;

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
                foreach ($template->templatePassengerCategories as $tier) {
                    $instance->tripPassengerCategories()->create([
                        'name' => $tier->name,
                        'price' => $tier->price,
                    ]);
                }

                // Create Addons
                foreach ($template->templateAddons as $addon) {
                    $instance->tripAddons()->create([
                        'name' => $addon->name,
                        'price' => $addon->price,
                        'max_quantity' => $addon->max_quantity,
                    ]);
                }

                // Attach Pickup Routes
                if (!empty($this->pickupRouteIds)) {
                    $instance->pickupRoutes()->attach($this->pickupRouteIds);
                }

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
    }
}
