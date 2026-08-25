<?php

namespace App\Filament\Resources\BookingResource\Pages;

use App\Filament\Resources\BookingResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditBooking extends EditRecord
{
    protected static string $resource = BookingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $record = $this->getRecord();
        $currentStatus = $record->booking_status instanceof \App\Enums\BookingStatus
            ? $record->booking_status->value
            : $record->booking_status;
        $newStatus = ($data['booking_status'] ?? $currentStatus) instanceof \App\Enums\BookingStatus
            ? ($data['booking_status'] ?? $currentStatus)->value
            : ($data['booking_status'] ?? $currentStatus);

        // Terminal states cannot be changed
        if (in_array($currentStatus, ['cancelled']) && $newStatus !== $currentStatus) {
            \Filament\Notifications\Notification::make()
                ->title('لا يمكن تغيير الحالة')
                ->body('الحجوزات الملغاة لا يمكن تغيير حالتها.')
                ->danger()
                ->send();

            $data['booking_status'] = $currentStatus; // Revert
        }

        return $data;
    }
}
