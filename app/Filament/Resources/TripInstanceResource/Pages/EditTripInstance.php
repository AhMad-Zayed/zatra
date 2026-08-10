<?php

namespace App\Filament\Resources\TripInstanceResource\Pages;

use App\Filament\Resources\TripInstanceResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTripInstance extends EditRecord
{
    protected static string $resource = TripInstanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('generate_manifest')
                ->label('تحميل كشف الركاب (PDF)')
                ->icon('heroicon-o-document-text')
                ->color('success')
                ->url(fn (\App\Models\TripInstance $record): string => route('trip-instance.manifest', $record))
                ->openUrlInNewTab(),
                
            Actions\Action::make('copy_guide_link')
                ->label('نسخ رابط المرشد')
                ->icon('heroicon-o-link')
                ->color('info')
                ->action(function (\App\Models\TripInstance $record) {
                    // Do nothing on backend
                })
                ->modalHeading('رابط المرشد السياحي')
                ->modalDescription('انسخ هذا الرابط وأرسله للمرشد السياحي عبر الواتساب. لا يحتاج المرشد لتسجيل الدخول.')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('إغلاق')
                ->form([
                    \Filament\Forms\Components\TextInput::make('guide_url')
                        ->label('الرابط السري (ينتهي بانتهاء الرحلة)')
                        ->default(fn (\App\Models\TripInstance $record) => url('/g/' . $record->uuid))
                        ->disabled()
                        ->extraInputAttributes(['readonly' => true]),
                ]),
                
            Actions\DeleteAction::make(),
        ];
    }
}
