<?php

namespace App\Filament\Resources\WaitingListResource\Pages;

use App\Filament\Resources\WaitingListResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateWaitingList extends CreateRecord
{
    protected static string $resource = WaitingListResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['tenant_id'] = auth()->user()?->tenant_id ?? 1;
        return $data;
    }
}
