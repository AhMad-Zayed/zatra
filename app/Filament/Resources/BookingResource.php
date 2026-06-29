<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BookingResource\Pages;
use App\Filament\Resources\BookingResource\RelationManagers;
use App\Models\Booking;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class BookingResource extends Resource
{
    protected static ?string $model = Booking::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static ?string $recordTitleAttribute = 'pnr';

    public static function getGloballySearchableAttributes(): array
    {
        return ['pnr', 'customer.phone', 'customer.name'];
    }

    public static function getNavigationLabel(): string
    {
        return 'الحجوزات';
    }

    public static function getModelLabel(): string
    {
        return 'حجز';
    }

    public static function getPluralModelLabel(): string
    {
        return 'الحجوزات';
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Group::make()
                    ->schema([
                        Forms\Components\Section::make('معلومات الحجز')
                            ->schema([
                                Forms\Components\Select::make('customer_id')
                                    ->relationship(
                                        name: 'customer', 
                                        titleAttribute: 'phone',
                                        modifyQueryUsing: fn (Builder $query) => $query->where('tenant_id', \Filament\Facades\Filament::getTenant()?->id ?? auth()->user()->tenants()->first()->id)
                                    )
                                    ->getOptionLabelFromRecordUsing(fn (\Illuminate\Database\Eloquent\Model $record) => "{$record->name} - {$record->phone}")
                                    ->label('العميل (Lead Customer)')
                                    ->searchable()
                                    ->required()
                                    ->createOptionForm([
                                        Forms\Components\TextInput::make('name')
                                            ->label('الاسم')
                                            ->required()
                                            ->maxLength(255),
                                        Forms\Components\TextInput::make('phone')
                                            ->label('رقم الهاتف')
                                            ->required()
                                            ->tel()
                                            ->maxLength(255),
                                    ])
                                    ->createOptionAction(fn (\Filament\Forms\Components\Actions\Action $action) => $action->mutateFormDataBeforeCreateUsing(function (array $data): array {
                                        $data['tenant_id'] = \Filament\Facades\Filament::getTenant()?->id ?? auth()->user()->tenants()->first()->id;
                                        return $data;
                                    }))
                                    ->disabledOn('edit'),
                                
                                Forms\Components\Select::make('trip_instance_id')
                                    ->label('موعد الرحلة (Trip Instance)')
                                    ->options(function () {
                                        return \App\Models\TripInstance::with('tripTemplate')->get()->mapWithKeys(function ($instance) {
                                            return [$instance->id => $instance->tripTemplate->title . ' (' . $instance->start_date . ' الى ' . $instance->end_date . ')'];
                                        });
                                    })
                                    ->searchable()
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(function (Forms\Set $set) {
                                        $set('passengers', []);
                                        $set('bookingAddons', []);
                                    })
                                    ->disabledOn('edit'),
                                
                                Forms\Components\Select::make('booking_status')
                                    ->label('حالة الحجز')
                                    ->options(\App\Enums\BookingStatus::class)
                                    ->default(\App\Enums\BookingStatus::Pending)
                                    ->required(),
                                
                                Forms\Components\Select::make('payment_status')
                                    ->label('حالة الدفع')
                                    ->options(\App\Enums\PaymentStatus::class)
                                    ->default(\App\Enums\PaymentStatus::Unpaid)
                                    ->disabled(),

                                Forms\Components\TextInput::make('pnr')
                                    ->label('الرقم المرجعي (PNR)')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->visibleOn(['view', 'edit']),

                                Forms\Components\DateTimePicker::make('expires_at')
                                    ->label('تاريخ الانتهاء للدفع النقدي')
                                    ->nullable()
                                    ->native(false),
                            ])->columns(2),

                        Forms\Components\Section::make('المسافرون (Passengers)')
                            ->schema([
                                Forms\Components\Repeater::make('passengers')
                                    ->relationship()
                                    ->schema([
                                        Forms\Components\Select::make('trip_passenger_category_id')
                                            ->label('فئة التسعير')
                                            ->options(function (Forms\Get $get) {
                                                $instanceId = $get('../../trip_instance_id');
                                                if (!$instanceId) return [];
                                                return \App\Models\TripPassengerCategory::where('trip_instance_id', $instanceId)->pluck('name', 'id');
                                            })
                                            ->required()
                                            ->live()
                                            ->afterStateUpdated(function (Forms\Set $set, ?string $state) {
                                                if ($state) {
                                                    $tier = \App\Models\TripPassengerCategory::find($state);
                                                    if ($tier) {
                                                        $set('unit_price', $tier->price);
                                                    }
                                                }
                                            })
                                            ->disabledOn('edit'),
                                        
                                        Forms\Components\TextInput::make('unit_price')
                                            ->label('السعر')
                                            ->numeric()
                                            ->readOnly()
                                            ->prefix('$')
                                            ->dehydrated(false),
                                            
                                        Forms\Components\KeyValue::make('dynamic_data')
                                            ->label('معلومات إضافية')
                                            ->disabledOn('edit')
                                            ->columnSpanFull(),
                                    ])
                                    ->columns(2)
                                    ->live()
                                    ->disabledOn('edit'),
                            ]),

                        Forms\Components\Section::make('الإضافات (Addons)')
                            ->schema([
                                Forms\Components\Repeater::make('bookingAddons')
                                    ->relationship()
                                    ->schema([
                                        Forms\Components\Select::make('trip_addon_id')
                                            ->label('الإضافة')
                                            ->options(function (Forms\Get $get) {
                                                $instanceId = $get('../../trip_instance_id');
                                                if (!$instanceId) return [];
                                                return \App\Models\TripAddon::where('trip_instance_id', $instanceId)->pluck('name', 'id');
                                            })
                                            ->required()
                                            ->live()
                                            ->afterStateUpdated(function (Forms\Set $set, ?string $state, Forms\Get $get) {
                                                if ($state) {
                                                    $addon = \App\Models\TripAddon::find($state);
                                                    if ($addon) {
                                                        $set('unit_price', $addon->price);
                                                        $qty = $get('quantity') ?: 1;
                                                        $set('total_price', $addon->price * $qty);
                                                    }
                                                }
                                            })
                                            ->disabledOn('edit'),
                                        
                                        Forms\Components\TextInput::make('quantity')
                                            ->label('الكمية')
                                            ->numeric()
                                            ->default(1)
                                            ->minValue(1)
                                            ->required()
                                            ->live()
                                            ->afterStateUpdated(function (Forms\Set $set, ?string $state, Forms\Get $get) {
                                                $price = $get('unit_price') ?: 0;
                                                $set('total_price', $price * ($state ?: 1));
                                            })
                                            ->disabledOn('edit'),
                                            
                                        Forms\Components\TextInput::make('unit_price')
                                            ->label('سعر الوحدة')
                                            ->numeric()
                                            ->readOnly()
                                            ->prefix('$')
                                            ->dehydrated(false),
                                            
                                        Forms\Components\TextInput::make('total_price')
                                            ->label('الإجمالي')
                                            ->numeric()
                                            ->readOnly()
                                            ->prefix('$')
                                            ->dehydrated(false),
                                    ])
                                    ->columns(4)
                                    ->live()
                                    ->disabledOn('edit'),
                            ]),
                    ])
                    ->columnSpan(['lg' => 2]),

                Forms\Components\Group::make()
                    ->schema([
                        Forms\Components\Section::make('ملخص الحساب')
                            ->schema([
                                Forms\Components\Placeholder::make('grand_total_placeholder')
                                    ->label('الإجمالي الكلي')
                                    ->content(function (Forms\Get $get) {
                                        $total = 0;
                                        
                                        $passengers = $get('passengers');
                                        if (is_array($passengers)) {
                                            foreach ($passengers as $p) {
                                                $total += (float) ($p['unit_price'] ?? 0);
                                            }
                                        }
                                        
                                        $addons = $get('bookingAddons');
                                        if (is_array($addons)) {
                                            foreach ($addons as $a) {
                                                $total += (float) ($a['total_price'] ?? 0);
                                            }
                                        }
                                        
                                        return '$' . number_format($total, 2);
                                    }),
                                    
                                Forms\Components\TextInput::make('grand_total')
                                    ->label('الإجمالي (مسجل بالرقم)')
                                    ->numeric()
                                    ->disabled()
                                    ->prefix('$')
                                    ->visibleOn('view'),
                                    
                                Forms\Components\TextInput::make('total_paid')
                                    ->label('المدفوع')
                                    ->numeric()
                                    ->disabled()
                                    ->prefix('$')
                                    ->visibleOn('view'),
                                    
                                Forms\Components\TextInput::make('balance_due')
                                    ->label('المتبقي')
                                    ->numeric()
                                    ->disabled()
                                    ->prefix('$')
                                    ->visibleOn('view'),
                            ]),
                            
                        Forms\Components\Section::make('ملاحظات')
                            ->schema([
                                Forms\Components\Textarea::make('notes')
                                    ->label('ملاحظات الحجز')
                                    ->rows(3),
                            ]),
                    ])
                    ->columnSpan(['lg' => 1]),
            ])
            ->columns(3);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('pnr')
                    ->label('المرجع (PNR)')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->copyMessage('تم النسخ!')
                    ->copyMessageDuration(1500)
                    ->weight('bold')
                    ->color('primary'),
                Tables\Columns\TextColumn::make('customer.name')
                    ->label('العميل')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('tripInstance.tripTemplate.title')
                    ->label('الرحلة')
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاريخ الحجز')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('grand_total')
                    ->label('الإجمالي')
                    ->money('USD')
                    ->sortable(),
                Tables\Columns\TextColumn::make('balance_due')
                    ->label('المتبقي')
                    ->money('USD')
                    ->color(fn ($state) => $state == 0 ? 'success' : 'danger')
                    ->sortable(),
                Tables\Columns\TextColumn::make('booking_status')
                    ->label('حالة الحجز')
                    ->badge(),
                Tables\Columns\TextColumn::make('payment_status')
                    ->label('حالة الدفع')
                    ->badge(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('trip_instance_id')
                    ->label('الرحلة (Trip Instance)')
                    ->options(fn () => \App\Models\TripInstance::with('tripTemplate')->get()->mapWithKeys(fn ($i) => [$i->id => $i->tripTemplate->title . ' (' . $i->start_date->format('Y-m-d') . ')']))
                    ->searchable(),
                Tables\Filters\Filter::make('created_at')
                    ->form([
                        Forms\Components\DatePicker::make('created_from')->label('من تاريخ'),
                        Forms\Components\DatePicker::make('created_until')->label('إلى تاريخ'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['created_from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '>=', $date),
                            )
                            ->when(
                                $data['created_until'],
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '<=', $date),
                            );
                    }),
                Tables\Filters\SelectFilter::make('payment_status')
                    ->label('حالة الدفع')
                    ->options(\App\Enums\PaymentStatus::class),
            ])
            ->actions([
                Tables\Actions\Action::make('confirm_cash')
                    ->label('تأكيد الدفع النقدي')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('تأكيد استلام المبلغ النقدي')
                    ->modalDescription('هل أنت متأكد من استلام كامل المبلغ نقداً؟ سيتم تغيير حالة الحجز إلى مؤكد وإصدار التذكرة النهائية.')
                    ->visible(fn (Booking $record) => $record->booking_status === \App\Enums\BookingStatus::Pending && $record->payment_status === \App\Enums\PaymentStatus::Unpaid)
                    ->action(function (Booking $record) {
                        \Illuminate\Support\Facades\DB::transaction(function () use ($record) {
                            $record->update([
                                'booking_status' => \App\Enums\BookingStatus::Confirmed,
                                'payment_status' => \App\Enums\PaymentStatus::Paid,
                                'total_paid' => $record->grand_total,
                                'balance_due' => 0,
                            ]);

                            // Create payment ledger entry
                            $record->payments()->create([
                                'tenant_id' => $record->tenant_id,
                                'amount' => $record->grand_total,
                                'payment_method' => 'cash',
                                'status' => 'completed',
                                'transaction_id' => 'CASH-' . time(),
                                'paid_at' => now(),
                            ]);

                            // Trigger Final PDF Notification
                            $message = "تم تأكيد استلام الدفعة النقدية بنجاح لحجزك رقم {$record->pnr}. مرفق التذكرة النهائية.";
                            
                            if ($record->customer && $record->customer->phone) {
                                \App\Jobs\SendBookingNotificationJob::dispatch($record, 'whatsapp', $message);
                            }
                            if ($record->customer && $record->customer->email) {
                                \App\Jobs\SendBookingNotificationJob::dispatch($record, 'email', $message);
                            }
                        });

                        \Filament\Notifications\Notification::make()
                            ->title('تم تأكيد الدفع بنجاح')
                            ->success()
                            ->send();
                    }),
                    
                Tables\Actions\Action::make('process_cancellation')
                    ->label('معالجة الإلغاء')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('معالجة طلب الإلغاء واسترداد الأموال')
                    ->visible(fn (Booking $record) => $record->cancellation_requested_at !== null && $record->booking_status !== \App\Enums\BookingStatus::Cancelled)
                    ->form([
                        Forms\Components\TextInput::make('cancellation_fee')
                            ->label('رسوم الإلغاء (يتم خصمها من المدفوع)')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(fn ($record) => $record->total_paid)
                            ->default(0)
                            ->helperText('أدخل المبلغ المراد خصمه كرسوم إلغاء.'),
                    ])
                    ->action(function (array $data, Booking $record) {
                        \Illuminate\Support\Facades\DB::transaction(function () use ($data, $record) {
                            $refundableAmount = $record->total_paid - $data['cancellation_fee'];
                            
                            $note = $record->notes . "\n[".now()."] تم المعالجة. رسوم الإلغاء: {$data['cancellation_fee']}. المبلغ المسترد الواجب إرجاعه للعميل: {$refundableAmount}.";
                            
                            $record->update([
                                'booking_status' => \App\Enums\BookingStatus::Cancelled,
                                'cancellation_requested_at' => null,
                                'notes' => trim($note),
                            ]);

                            \App\Jobs\ProcessWaitingListJob::dispatch($record->tripInstance);
                        });

                        \Filament\Notifications\Notification::make()
                            ->title('تمت معالجة الإلغاء بنجاح')
                            ->body('تم إخطار قائمة الانتظار بالمقعد الشاغر تلقائياً.')
                            ->success()
                            ->send();
                    }),
                    
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\PaymentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBookings::route('/'),
            'create' => Pages\CreateBooking::route('/create'),
            'view' => Pages\ViewBooking::route('/{record}'),
            'edit' => Pages\EditBooking::route('/{record}/edit'),
        ];
    }
}
