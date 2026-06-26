<?php

namespace App\Filament\Resources\GlobalPricingTierResource\Pages;

use App\Filament\Resources\GlobalPricingTierResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListGlobalPricingTiers extends ListRecords
{
    protected static string $resource = GlobalPricingTierResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
