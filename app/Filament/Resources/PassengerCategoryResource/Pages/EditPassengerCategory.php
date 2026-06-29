<?php

namespace App\Filament\Resources\PassengerCategoryResource\Pages;

use App\Filament\Resources\PassengerCategoryResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPassengerCategory extends EditRecord
{
    protected static string $resource = PassengerCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
