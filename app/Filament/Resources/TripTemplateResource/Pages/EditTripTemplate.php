<?php

namespace App\Filament\Resources\TripTemplateResource\Pages;

use App\Filament\Resources\TripTemplateResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTripTemplate extends EditRecord
{
    protected static string $resource = TripTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
