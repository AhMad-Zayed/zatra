<?php

namespace App\Filament\Resources\StaffResource\Pages;

use App\Filament\Resources\StaffResource;
use Filament\Resources\Pages\CreateRecord;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

class CreateStaff extends CreateRecord
{
    protected static string $resource = StaffResource::class;

    /**
     * Because Users and Tenants have a BelongsToMany (pivot) relationship,
     * mutating data before create (e.g., mutateFormDataBeforeCreate) would cause a SQL column error.
     * The safest architectural way to attach the newly created User to the current Tenant is during
     * or immediately after the record creation phase.
     */
    protected function handleRecordCreation(array $data): Model
    {
        // 1. Set the Spatie Permission Team ID before creation to ensure roles attach to the correct Tenant context
        $tenantId = Filament::getTenant()?->id;
        if ($tenantId) {
            setPermissionsTeamId($tenantId);
        }

        // 2. Create the User record
        $user = static::getModel()::create($data);

        // 3. Attach the User to the currently active Tenant via the tenant_user pivot table
        if ($tenantId) {
            $user->tenants()->attach($tenantId);
        }

        return $user;
    }
}
