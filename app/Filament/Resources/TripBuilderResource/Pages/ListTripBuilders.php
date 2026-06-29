<?php

namespace App\Filament\Resources\TripBuilderResource\Pages;

use App\Filament\Resources\TripBuilderResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTripBuilders extends ListRecords
{
    protected static string $resource = TripBuilderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
