<?php

namespace App\Filament\Resources\PickupRouteResource\Pages;

use App\Filament\Resources\PickupRouteResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPickupRoutes extends ListRecords
{
    protected static string $resource = PickupRouteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
