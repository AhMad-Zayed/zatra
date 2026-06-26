<?php

namespace App\Filament\Resources\TripTemplateResource\Pages;

use App\Filament\Resources\TripTemplateResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTripTemplates extends ListRecords
{
    protected static string $resource = TripTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
