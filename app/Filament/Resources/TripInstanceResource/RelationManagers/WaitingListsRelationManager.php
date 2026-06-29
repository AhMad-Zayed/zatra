<?php

namespace App\Filament\Resources\TripInstanceResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use App\Enums\WaitingListStatusEnum;
use Illuminate\Support\Facades\URL;

class WaitingListsRelationManager extends RelationManager
{
    protected static string $relationship = 'waitingLists';
    protected static ?string $title = 'قائمة الانتظار (Waiting List)';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('customer_name')
                    ->label('اسم الزبون')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('phone_number')
                    ->label('رقم الهاتف')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('customer_email')
                    ->label('البريد الإلكتروني')
                    ->email()
                    ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('customer_name')
            ->defaultSort('created_at', 'asc')
            ->columns([
                Tables\Columns\TextColumn::make('customer_name')
                    ->label('الاسم')
                    ->searchable(),
                Tables\Columns\TextColumn::make('phone_number')
                    ->label('رقم الهاتف')
                    ->searchable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->colors([
                        'gray' => WaitingListStatusEnum::Pending->value,
                        'info' => WaitingListStatusEnum::Notified->value,
                        'danger' => WaitingListStatusEnum::Expired->value,
                        'success' => WaitingListStatusEnum::Converted->value,
                    ]),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاريخ الإضافة (الطابور)')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('notified_at')
                    ->label('تاريخ الإشعار')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('حالة الطلب')
                    ->options([
                        WaitingListStatusEnum::Pending->value => 'قيد الانتظار',
                        WaitingListStatusEnum::Notified->value => 'تم إشعاره',
                        WaitingListStatusEnum::Expired->value => 'منتهي الصلاحية/ملغي',
                        WaitingListStatusEnum::Converted->value => 'تحول لحجز',
                    ]),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('إضافة للقائمة')
                    ->icon('heroicon-o-plus')
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['tenant_id'] = \Filament\Facades\Filament::getTenant()?->id ?? auth()->user()->tenant_id;
                        $data['status'] = WaitingListStatusEnum::Pending->value;
                        return $data;
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('send_link_now')
                    ->label('إرسال الرابط فوراً (VIP)')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('هل أنت متأكد من تجاوز الطابور وإرسال رابط الحجز لهذا العميل فوراً؟')
                    ->visible(fn ($record) => $record->status === WaitingListStatusEnum::Pending)
                    ->action(function ($record) {
                        $signedUrl = URL::temporarySignedRoute(
                            'waiting-list.redeem',
                            now()->addHours(2),
                            ['waitingList' => $record->id]
                        );

                        $record->update([
                            'status' => WaitingListStatusEnum::Notified,
                            'notified_at' => now(),
                        ]);

                        $channelPreference = $record->tenant->settings['waiting_list_channel'] ?? 'both';

                        if (in_array($channelPreference, ['whatsapp', 'both'])) {
                            if (class_exists(\App\Services\WhatsAppService::class)) {
                                app(\App\Services\WhatsAppService::class)->sendWaitingListAlert(
                                    $record->phone_number,
                                    $record->customer_name,
                                    $signedUrl
                                );
                            }
                        }

                        if (in_array($channelPreference, ['email', 'both']) && $record->customer_email) {
                            if (class_exists(\App\Mail\WaitingListAlertMail::class)) {
                                \Illuminate\Support\Facades\Mail::to($record->customer_email)
                                    ->send(new \App\Mail\WaitingListAlertMail($record, $signedUrl));
                            }
                        }

                        \Filament\Notifications\Notification::make()
                            ->title('تم إرسال الرابط للعميل بنجاح وتجاوز الطابور.')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('cancel_request')
                    ->label('إلغاء الطلب')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn ($record) => in_array($record->status, [WaitingListStatusEnum::Pending, WaitingListStatusEnum::Notified]))
                    ->action(function ($record) {
                        $record->update(['status' => WaitingListStatusEnum::Expired]);
                        \Filament\Notifications\Notification::make()
                            ->title('تم إلغاء الطلب وإخراجه من الطابور')
                            ->success()
                            ->send();
                    }),
            ]);
    }
}
