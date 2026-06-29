<?php

namespace App\Filament\Resources\StaffResource\Pages;

use App\Filament\Resources\StaffResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Filament\Facades\Filament;

class EditStaff extends EditRecord
{
    protected static string $resource = StaffResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->hidden(fn () => auth()->id() === $this->record->id), // Prevent deleting self
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        // 1. Ensure the correct Spatie Team ID is set before filling the form
        // so the 'roles' relationship retrieves the roles for the current tenant.
        $tenantId = Filament::getTenant()?->id;
        if ($tenantId) {
            setPermissionsTeamId($tenantId);
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // 2. Ensure Team ID is active during the save process so Roles are attached correctly
        $tenantId = Filament::getTenant()?->id;
        if ($tenantId) {
            setPermissionsTeamId($tenantId);
        }

        return $data;
    }
}
