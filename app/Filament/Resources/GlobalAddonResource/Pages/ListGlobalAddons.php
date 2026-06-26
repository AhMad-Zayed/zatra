<?php

namespace App\Filament\Resources\GlobalAddonResource\Pages;

use App\Filament\Resources\GlobalAddonResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListGlobalAddons extends ListRecords
{
    protected static string $resource = GlobalAddonResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
