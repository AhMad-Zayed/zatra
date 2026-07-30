<?php

namespace App\Filament\Resources\RequirementPresetResource\Pages;

use App\Filament\Resources\RequirementPresetResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewRequirementPreset extends ViewRecord
{
    protected static string $resource = RequirementPresetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
