<?php

namespace App\Filament\Resources\HotelResource\Pages;

use App\Filament\Resources\HotelResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditHotel extends EditRecord
{
    protected static string $resource = HotelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->before(function (Actions\DeleteAction $action) {
                    if ($this->record->tripStayLegHotelOptions()->count() > 0) {
                        \Filament\Notifications\Notification::make()
                            ->warning()
                            ->title('لا يمكن حذف الفندق')
                            ->body('هذا الفندق مستخدم في خيارات إقامة لرحلات موجودة. يرجى إلغاء تفعيله (أرشفته) بدلاً من حذفه.')
                            ->persistent()
                            ->send();

                        $action->cancel();
                    }
                }),
        ];
    }
}
