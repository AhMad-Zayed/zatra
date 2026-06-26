<?php

namespace App\Filament\Resources\TripInstanceResource\Pages;

use App\Filament\Resources\TripInstanceResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTripInstances extends ListRecords
{
    protected static string $resource = TripInstanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
