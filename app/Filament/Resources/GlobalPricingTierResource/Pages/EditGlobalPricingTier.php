<?php

namespace App\Filament\Resources\GlobalPricingTierResource\Pages;

use App\Filament\Resources\GlobalPricingTierResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditGlobalPricingTier extends EditRecord
{
    protected static string $resource = GlobalPricingTierResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
