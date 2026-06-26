<?php

namespace App\Filament\Resources\GlobalAddonResource\Pages;

use App\Filament\Resources\GlobalAddonResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditGlobalAddon extends EditRecord
{
    protected static string $resource = GlobalAddonResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
