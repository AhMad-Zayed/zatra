<?php

namespace App\Filament\Resources\TripBuilderResource\Pages;

use App\Filament\Resources\TripBuilderResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateTripBuilder extends CreateRecord
{
    protected static string $resource = TripBuilderResource::class;

    protected array $schedulingData = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Extract non-model fields
        $this->schedulingData = [
            'pickup_routes' => $data['pickup_routes'] ?? [],
            'seats_count' => $data['seats_count'] ?? 50,
            'schedule_type' => $data['schedule_type'] ?? 'single',
            'single_date' => $data['single_date'] ?? null,
            'recurring_start' => $data['recurring_start'] ?? null,
            'recurring_end' => $data['recurring_end'] ?? null,
            'recurring_days' => $data['recurring_days'] ?? [],
            'publish_immediately' => $data['publish_immediately'] ?? true,
        ];

        // Remove them from $data so Eloquent doesn't crash
        unset(
            $data['pickup_routes'],
            $data['seats_count'],
            $data['schedule_type'],
            $data['single_date'],
            $data['recurring_start'],
            $data['recurring_end'],
            $data['recurring_days'],
            $data['publish_immediately']
        );

        return $data;
    }

    protected function afterCreate(): void
    {
        $template = $this->record;
        $datesToCreate = [];

        if ($this->schedulingData['schedule_type'] === 'single') {
            if (!empty($this->schedulingData['single_date'])) {
                $datesToCreate[] = $this->schedulingData['single_date'];
            }
        } elseif ($this->schedulingData['schedule_type'] === 'recurring') {
            $start = \Carbon\Carbon::parse($this->schedulingData['recurring_start']);
            $end = \Carbon\Carbon::parse($this->schedulingData['recurring_end']);
            $allowedDays = $this->schedulingData['recurring_days'];

            while ($start->lte($end)) {
                if (in_array((string)$start->dayOfWeek, $allowedDays)) {
                    $datesToCreate[] = $start->toDateString();
                }
                $start->addDay();
            }
        }

        if (count($datesToCreate) > 0) {
            \App\Jobs\BulkGenerateTripInstances::dispatch(
                $template->id,
                $datesToCreate,
                (int) $this->schedulingData['seats_count'],
                $this->schedulingData['pickup_routes'],
                auth()->id()
            );
        }
    }
}
