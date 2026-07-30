<?php

namespace App\Filament\Resources\RequirementPresetResource\Pages;

use App\Filament\Resources\RequirementPresetResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditRequirementPreset extends EditRecord
{
    protected static string $resource = RequirementPresetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
