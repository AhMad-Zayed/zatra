<?php

namespace App\Filament\Resources\PassengerCategoryResource\Pages;

use App\Filament\Resources\PassengerCategoryResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPassengerCategories extends ListRecords
{
    protected static string $resource = PassengerCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
