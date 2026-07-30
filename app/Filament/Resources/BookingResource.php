<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BookingResource\Pages;
use App\Filament\Resources\BookingResource\RelationManagers;
use App\Models\Booking;
use App\Models\TripInstance;
use App\Models\TripPassengerCategory;
use App\Models\TripAddon;
use App\Models\PickupPoint;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use pxlrbt\FilamentExcel\Actions\Tables\ExportBulkAction;
use Illuminate\Support\Facades\DB;

class BookingResource extends Resource
{
    protected static ?string $model = Booking::class;

    protected static ?string $navigationIcon = 'heroicon-o-ticket';
    
    protected static ?string $navigationGroup = 'العمليات اليومية';
    protected static ?int $navigationSort = 1;
    protected static ?string $recordTitleAttribute = 'pnr';

    public static function getGloballySearchableAttributes(): array
    {
        return ['pnr', 'customer.phone', 'customer.name'];
    }

    // FIX N+1-001, N+1-002: Eager load customer and trip relationships to prevent N queries per table row
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with([
                'customer',
                'tripInstance.tripTemplate',
                'passengers',
            ]);
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
                                    // LABEL-001: Removed English parenthetical
                                    ->label('العميل الرئيسي')
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
                                    ->createOptionAction(fn (\Filament\Forms\Components\Actions\Action $action) => $action->mutateFormDataUsing(function (array $data): array {
                                        $data['tenant_id'] = \Filament\Facades\Filament::getTenant()?->id ?? auth()->user()->tenants()->first()->id;
                                        return $data;
                                    }))
                                    ->disabledOn('edit'),
                                
                                Forms\Components\Select::make('trip_instance_id')
                                    // LABEL-002: Removed English parenthetical
                                    ->label('موعد الرحلة')
                                    ->options(function () {
                                        return TripInstance::with('tripTemplate')->get()->mapWithKeys(function ($instance) {
                                            return [$instance->id => $instance->tripTemplate->title . ' (' . $instance->start_date . ' الى ' . $instance->end_date . ')'];
                                        });
                                    })
                                    ->searchable()
                                    ->required()
                                    ->live()
                                    // MISSING-INFO-001: Show remaining seats when selecting trip
                                    ->hint(fn (?string $state): ?string =>
                                        $state
                                            ? (TripInstance::find($state)?->remaining_seats ?? '?') . ' مقعد متاح'
                                            : null
                                    )
                                    ->hintColor(fn (?string $state): string =>
                                        $state && (TripInstance::find($state)?->remaining_seats ?? 1) <= 5
                                            ? 'danger' : 'success'
                                    )
                                    ->afterStateUpdated(function (Forms\Set $set) {
                                        $set('passengers', []);
                                        $set('bookingAddons', []);
                                        $set('package_option_id', null);
                                    })
                                    ->disabledOn('edit'),
                                
                                Forms\Components\Select::make('package_option_id')
                                    ->label('باقة الإقامة')
                                    ->options(fn (Forms\Get $get): array =>
                                        \App\Models\PackageOption::where('trip_instance_id', $get('trip_instance_id'))
                                            ->where('is_active', true)
                                            ->orderBy('sort_order')
                                            ->get()
                                            ->mapWithKeys(fn ($p) => [
                                                $p->id => $p->name 
                                                    . ($p->hotel_name ? ' — ' . $p->hotel_name : '')
                                                    . ($p->stars ? ' ' . str_repeat('★', $p->stars) : '')
                                                    . ' (+$' . number_format($p->price_adjustment / 100, 2) . ')'
                                            ])
                                            ->toArray()
                                    )
                                    ->nullable()
                                    ->placeholder('بدون إقامة / رحلة داخلية')
                                    ->live()
                                    ->hidden(fn (Forms\Get $get) => 
                                        !$get('trip_instance_id') ||
                                        \App\Models\PackageOption::where('trip_instance_id', $get('trip_instance_id'))
                                                     ->where('is_active', true)
                                                     ->count() === 0
                                    )
                                    ->hint(fn (Forms\Get $get): ?string =>
                                        $get('package_option_id')
                                            ? '$' . number_format(
                                                \App\Models\PackageOption::find($get('package_option_id'))?->price_adjustment ?? 0, 
                                                2
                                              ) . ' إضافي على سعر الرحلة'
                                            : null
                                    )
                                    ->hintColor('warning'),
                                
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
                                    // CRIT-007: Added default of +24h so new cash bookings don't expire immediately
                                    ->label('موعد انتهاء مهلة الدفع')
                                    ->default(now()->addHours(24))
                                    ->helperText('سيتم إلغاء الحجز تلقائياً إذا لم يُسدَّد المبلغ قبل هذا الوقت')
                                    ->required()
                                    ->native(false),
                            ])->columns(2),

                        // LABEL-003: Pure Arabic section name
                        Forms\Components\Section::make('بيانات المسافرين')
                            ->schema([
                                Forms\Components\Repeater::make('passengers')
                                    ->relationship()
                                    // VALID-001: Require at least one passenger
                                    ->minItems(1)
                                    ->helperText('يجب إضافة راكب واحد على الأقل لإتمام الحجز')
                                    ->schema([
                                        // CRIT-003: Replaced non-existent dynamic_data with real Passenger model fields
                                        Forms\Components\TextInput::make('first_name')
                                            ->label('الاسم الأول')
                                            ->required()
                                            ->maxLength(255),
                                        Forms\Components\TextInput::make('last_name')
                                            ->label('اسم العائلة')
                                            ->required()
                                            ->maxLength(255),
                                        Forms\Components\Select::make('document_type')
                                            ->label('نوع الوثيقة')
                                            ->options([
                                                'national_id' => 'هوية وطنية',
                                                'passport'    => 'جواز سفر',
                                            ])
                                            ->required(),
                                        Forms\Components\TextInput::make('document_number')
                                            ->label('رقم الوثيقة')
                                            ->required()
                                            ->maxLength(255),
                                        Forms\Components\DatePicker::make('date_of_birth')
                                            ->label('تاريخ الميلاد')
                                            ->nullable(),
                                        Forms\Components\Select::make('gender')
                                            ->label('الجنس')
                                            ->options(['male' => 'ذكر', 'female' => 'أنثى'])
                                            ->nullable(),
                                        Forms\Components\Select::make('trip_passenger_category_id')
                                            ->label('فئة المسافر والسعر')
                                            ->options(function (Forms\Get $get) {
                                                $instanceId = $get('../../trip_instance_id');
                                                if (!$instanceId) return [];
                                                return TripPassengerCategory::where('trip_instance_id', $instanceId)->pluck('name', 'id');
                                            })
                                            ->required()
                                            ->live()
                                            ->afterStateUpdated(function (Forms\Set $set, ?string $state) {
                                                if ($state) {
                                                    $tier = TripPassengerCategory::find($state);
                                                    if ($tier) {
                                                        // Price is stored in cents, display in dollars
                                                        $set('unit_price', round($tier->price / 100, 2));
                                                    }
                                                }
                                            })
                                            ->disabledOn('edit'),

                                        // CRIT-004: Removed ->dehydrated(false) so unit_price participates in Livewire state
                                        // and the grand total placeholder can correctly sum it
                                        Forms\Components\TextInput::make('unit_price')
                                            ->label('السعر')
                                            ->numeric()
                                            ->readOnly()
                                            ->prefix('$')
                                            ->live(),

                                        Forms\Components\Select::make('pickup_point_id')
                                            ->label('نقطة التجمع')
                                            ->options(function (Forms\Get $get) {
                                                $instanceId = $get('../../trip_instance_id');
                                                if (!$instanceId) return [];
                                                return PickupPoint::whereHas('pickupRoute.tripInstances', fn ($q) =>
                                                    $q->where('trip_instances.id', $instanceId)
                                                )->pluck('name', 'id');
                                            })
                                            ->nullable(),
                                    ])
                                    ->columns(3)
                                    ->live()
                                    ->disabledOn('edit'),
                            ]),

                        // LABEL-004: Pure Arabic section name
                        Forms\Components\Section::make('الخدمات الإضافية')
                            ->schema([
                                Forms\Components\Repeater::make('bookingAddons')
                                    ->relationship()
                                    ->schema([
                                        Forms\Components\Select::make('passenger_id')
                                            ->label('المسافر (اختياري)')
                                            ->options(function (Forms\Get $get) {
                                                // Load from the state of the form
                                                $passengers = $get('../../passengers') ?? [];
                                                $options = [];
                                                foreach ($passengers as $key => $p) {
                                                    // CRIT-003: Updated to use new first_name/last_name fields instead of dynamic_data
                                                    $name = ($p['first_name'] ?? 'مسافر') . ' ' . ($p['last_name'] ?? '');
                                                    $options[$key] = $name; // Filament repeater keys are UUIDs, not DB IDs unless saved
                                                }
                                                // Actually, it's safer to query the DB if editing, or rely on the relationship if available.
                                                // Since it's a relationship repeater, we can just load the actual passengers.
                                                // Wait, if it's creating, the passengers don't have IDs yet. So linking by ID might fail.
                                                // Let's query if booking exists.
                                                $bookingId = $get('../../id');
                                                if ($bookingId) {
                                                    return \App\Models\Passenger::where('booking_id', $bookingId)
                                                        ->get()
                                                        ->mapWithKeys(fn ($p) => [$p->id => $p->first_name . ' ' . $p->last_name]);
                                                }
                                                return [];
                                            })
                                            ->searchable()
                                            ->disabledOn('edit'),

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
                                // CRIT-004: Grand total now works because unit_price is no longer dehydrated(false)
                                // unit_price is set in dollars (already /100 from cents) so we sum directly
                                Forms\Components\Placeholder::make('grand_total_placeholder')
                                    ->label('الإجمالي الكلي')
                                    ->live()
                                    ->content(function (Forms\Get $get): string {
                                        $total = 0.0;

                                        $passengers = $get('passengers');
                                        if (is_array($passengers)) {
                                            foreach ($passengers as $p) {
                                                $total += (float) ($p['unit_price'] ?? 0);
                                            }
                                        }

                                        $addons = $get('bookingAddons');
                                        if (is_array($addons)) {
                                            foreach ($addons as $a) {
                                                // addon total_price is also stored in dollars in the UI
                                                $total += (float) ($a['total_price'] ?? 0);
                                            }
                                        }

                                        $packageAdj = \App\Models\PackageOption::find($get('package_option_id'))?->price_adjustment ?? 0;
                                        $total += $packageAdj;

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
                Tables\Columns\TextColumn::make('payment_status')
                    ->label('حالة الدفع')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاريخ الحجز')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('passengers_count')
                    ->label('المسافرين')
                    ->counts('passengers')
                    ->badge()
                    ->color('info')
                    ->sortable(),
                Tables\Columns\TextColumn::make('grand_total')
                    ->label('الإجمالي')
                    ->money('USD')
                    ->sortable(),
                // CRIT-002: balance_due IS a real DB column (integer cents) after migration
                // MoneyCast handles /100 on read, so ->money('USD') works correctly.
                // The color function receives cents (raw DB integer via ->sum), but column
                // state goes through MoneyCast, so $state is in dollars. Compare with 0.
                Tables\Columns\TextColumn::make('balance_due')
                    ->label('المتبقي')
                    ->money('USD')
                    ->color(fn ($state) => $state <= 0 ? 'success' : 'danger')
                    ->sortable(),
                // MEDIUM-001: Passenger count in table
                Tables\Columns\TextColumn::make('passengers_count')
                    ->label('المسافرون')
                    ->counts('passengers')
                    ->badge()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('booking_status')
                    ->label('حالة الحجز')
                    ->badge(),
                Tables\Columns\TextColumn::make('payment_status')
                    ->label('حالة الدفع')
                    ->badge(),
                // MEDIUM-002: Show which staff created this booking
                Tables\Columns\TextColumn::make('user.name')
                    ->label('أنشأه')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('booking_status')
                    ->label('حالة الحجز')
                    ->options(\App\Enums\BookingStatus::class),
                Tables\Filters\SelectFilter::make('trip_instance_id')
                    ->label('الرحلة')
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
                Tables\Filters\SelectFilter::make('booking_status')
                    ->label('حالة الحجز')
                    ->options(\App\Enums\BookingStatus::class),
                Tables\Filters\SelectFilter::make('payment_status')
                    ->label('حالة الدفع')
                    ->options(\App\Enums\PaymentStatus::class),
            ])
            ->actions([
                Tables\Actions\Action::make('send_whatsapp_ticket')
                    ->label('إرسال التذكرة واتساب')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Booking $record) => in_array($record->booking_status, [\App\Enums\BookingStatus::Confirmed, \App\Enums\BookingStatus::ConfirmedPartial]))
                    ->action(function (Booking $record) {
                        $message = "مرحباً، مرفق تذكرة الحجز الخاصة بك برقم {$record->pnr}. نتمنى لك رحلة سعيدة!";
                        \App\Jobs\SendBookingNotificationJob::dispatch($record, 'whatsapp', $message);
                        \Filament\Notifications\Notification::make()->title('تم إرسال التذكرة بنجاح')->success()->send();
                    }),
                    
                Tables\Actions\Action::make('confirm_deposit')
                    ->label('تأكيد مع عربون')
                    ->icon('heroicon-o-currency-dollar')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('تأكيد الحجز بدفع عربون')
                    ->form([
                        Forms\Components\TextInput::make('deposit_amount')
                            ->label('قيمة العربون')
                            ->numeric()
                            ->required()
                            ->minValue(1)
                            ->maxValue(fn (Booking $record) => $record->grand_total),
                    ])
                    ->visible(fn (Booking $record) => $record->booking_status === \App\Enums\BookingStatus::Pending && $record->payment_status === \App\Enums\PaymentStatus::Unpaid)
                    ->action(function (array $data, Booking $record) {
                        \Illuminate\Support\Facades\DB::transaction(function () use ($data, $record) {
                            $deposit = $data['deposit_amount'];
                            $record->update([
                                'booking_status' => \App\Enums\BookingStatus::ConfirmedPartial,
                                'payment_status' => \App\Enums\PaymentStatus::Partial,
                                'total_paid' => $deposit,
                                'balance_due' => $record->grand_total - $deposit,
                                'payment_type' => 'deposit',
                            ]);
                            
                            $record->payments()->create([
                                'tenant_id' => $record->tenant_id,
                                'amount' => $deposit,
                                'payment_method' => 'cash',
                                'status' => 'completed',
                                'transaction_id' => 'DEP-' . time(),
                                'type' => \App\Enums\PaymentType::DEPOSIT,
                            ]);
                        });
                        \Filament\Notifications\Notification::make()->title('تم تأكيد العربون بنجاح')->success()->send();
                    }),
                    
                Tables\Actions\Action::make('reopen_cancelled')
                    ->label('إعادة فتح الحجز')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (Booking $record) => $record->booking_status === \App\Enums\BookingStatus::Cancelled && auth()->user()?->hasRole('agency_admin'))
                    ->action(function (Booking $record) {
                        $record->update(['booking_status' => \App\Enums\BookingStatus::Pending]);
                        \Filament\Notifications\Notification::make()->title('تم إعادة فتح الحجز كمسودة')->success()->send();
                    }),

                Tables\Actions\Action::make('confirm_cash')
                    ->label('تأكيد الدفع النقدي')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('تأكيد استلام المبلغ النقدي')
                    ->modalDescription('هل أنت متأكد من استلام كامل المبلغ نقداً؟ سيتم تغيير حالة الحجز إلى مؤكد وإصدار التذكرة النهائية.')
                    // SEC-004: Restrict cash confirmation to admin and accountant only
                    ->visible(fn (Booking $record) =>
                        $record->booking_status === \App\Enums\BookingStatus::Pending
                        && $record->payment_status === \App\Enums\PaymentStatus::Unpaid
                        && auth()->user()?->hasAnyRole(['agency_admin', 'accountant'])
                    )
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
                    
                Tables\Actions\Action::make('collect_balance')
                    ->label('تحصيل الرصيد المتبقي')
                    ->icon('heroicon-o-currency-dollar')
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalHeading('استلام الرصيد المتبقي')
                    ->modalDescription('هل أنت متأكد من استلام باقي المبلغ؟ سيتم تغيير حالة الحجز إلى مؤكد.')
                    ->visible(fn (Booking $record) => $record->booking_status === \App\Enums\BookingStatus::ConfirmedPartial && $record->balance_due > 0)
                    ->action(function (Booking $record) {
                        \Illuminate\Support\Facades\DB::transaction(function () use ($record) {
                            $balance = $record->balance_due;
                            $record->update([
                                'booking_status' => \App\Enums\BookingStatus::Confirmed,
                                'payment_status' => \App\Enums\PaymentStatus::Paid,
                                'total_paid' => $record->grand_total,
                                'balance_due' => 0,
                            ]);

                            // Create payment ledger entry for the remaining balance
                            $record->payments()->create([
                                'tenant_id' => $record->tenant_id,
                                'amount' => $balance,
                                'payment_method' => 'cash',
                                'status' => 'completed',
                                'transaction_id' => 'BALANCE-' . time(),
                                'paid_at' => now(),
                            ]);

                            // Trigger Final Notification
                            $message = "تم استلام الرصيد المتبقي بنجاح وتأكيد حجزك النهائي برقم {$record->pnr}.";
                            
                            if ($record->customer && $record->customer->phone) {
                                \App\Jobs\SendBookingNotificationJob::dispatch($record, 'whatsapp', $message);
                            }
                            if ($record->customer && $record->customer->email) {
                                \App\Jobs\SendBookingNotificationJob::dispatch($record, 'email', $message);
                            }
                        });

                        \Filament\Notifications\Notification::make()
                            ->title('تم تحصيل الرصيد بنجاح')
                            ->success()
                            ->send();
                    }),
                    
                Tables\Actions\Action::make('process_cancellation')
                    ->label('معالجة الإلغاء')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('معالجة طلب الإلغاء واسترداد الأموال')
                    // SEC-005: Restrict cancellation processing to admin and accountant only
                    ->visible(fn (Booking $record) =>
                        $record->cancellation_requested_at !== null
                        && $record->booking_status !== \App\Enums\BookingStatus::Cancelled
                        && auth()->user()?->hasAnyRole(['agency_admin', 'accountant'])
                    )
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

                            \App\Models\InventoryLedger::create([
                                'trip_instance_id' => $record->trip_instance_id,
                                'booking_id'       => $record->id,
                                'quantity'         => $record->passengers()->count(), // positive = returning seats
                                'type'             => 'cancellation_reversal',
                                'expires_at'       => null,
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
                    // SEC-001: Restrict bulk delete to agency_admin only
                    Tables\Actions\DeleteBulkAction::make()
                        ->visible(fn () => auth()->user()?->hasRole('agency_admin')),
                        
                    ExportBulkAction::make()
                        ->label('تصدير إلى Excel')
                        ->visible(fn () => auth()->user()?->hasAnyRole(['agency_admin', 'accountant'])),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\PassengersRelationManager::class,
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
