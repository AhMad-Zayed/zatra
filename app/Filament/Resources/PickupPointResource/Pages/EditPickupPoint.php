<?php

namespace App\Filament\Resources\PickupPointResource\Pages;

use App\Filament\Resources\PickupPointResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPickupPoint extends EditRecord
{
    protected static string $resource = PickupPointResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
