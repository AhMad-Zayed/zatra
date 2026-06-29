<?php

namespace App\Filament\Resources\PickupRouteResource\Pages;

use App\Filament\Resources\PickupRouteResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPickupRoute extends EditRecord
{
    protected static string $resource = PickupRouteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
