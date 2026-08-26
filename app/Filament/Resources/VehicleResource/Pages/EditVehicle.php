<?php

namespace App\Filament\Resources\VehicleResource\Pages;

use App\Filament\Resources\VehicleResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditVehicle extends EditRecord
{
    protected static string $resource = VehicleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->before(function (Actions\DeleteAction $action) {
                    if ($this->record->tripBusAssignments()->count() > 0) {
                        \Filament\Notifications\Notification::make()
                            ->warning()
                            ->title('لا يمكن حذف الحافلة')
                            ->body('هذه الحافلة مستخدمة في تخصيصات رحلات موجودة. يرجى إلغاء تفعيلها (أرشفتها) بدلاً من حذفها.')
                            ->persistent()
                            ->send();

                        $action->cancel();
                    }
                }),
        ];
    }
}
