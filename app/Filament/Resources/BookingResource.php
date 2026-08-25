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
    
    protected static ?string $navigationGroup = 'الحجوزات والعملاء';
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
                                    ->label('موعد الرحلة')
                                    ->options(function () {
                                        return TripInstance::with('tripTemplate')
                                            ->where('start_date', '>=', now()->subDays(1)) // Show current and future
                                            ->orderBy('start_date', 'asc')
                                            ->get()
                                            ->mapWithKeys(function ($instance) {
                                                return [$instance->id => $instance->tripTemplate->title . ' (' . $instance->start_date->format('Y-m-d') . ')'];
                                            });
                                    })
                                    ->searchable()
                                    ->preload() // Shows all options without typing
                                    ->required()
                                    ->live()
                                    ->hint(fn (?string $state): ?string =>
                                        $state
                                            ? (TripInstance::find($state)?->remaining_seats ?? '?') . ' مقعد متاح'
                                            : null
                                    )
                                    ->hintColor(fn (?string $state): string =>
                                        $state && (TripInstance::find($state)?->remaining_seats ?? 1) <= 5
                                            ? 'danger' : 'success'
                                    )
                                    ->suffixAction(
                                        \Filament\Forms\Components\Actions\Action::make('add_to_waitlist')
                                            ->label('قائمة الانتظار')
                                            ->icon('heroicon-o-clock')
                                            ->color('warning')
                                            ->tooltip('إضافة العميل لقائمة الانتظار لهذه الرحلة')
                                            ->hidden(fn (Forms\Get $get) => !$get('trip_instance_id'))
                                            ->form([
                                                Forms\Components\TextInput::make('seats_requested')
                                                    ->label('عدد المقاعد المطلوبة')
                                                    ->numeric()
                                                    ->required()
                                                    ->minValue(1)
                                                    ->default(1),
                                            ])
                                            ->action(function (array $data, Forms\Get $get, \Filament\Forms\Components\Actions\Action $action) {
                                                $tripId = $get('trip_instance_id');
                                                $customerId = $get('customer_id');
                                                
                                                if (!$customerId) {
                                                    \Filament\Notifications\Notification::make()->danger()->title('الرجاء اختيار العميل من القائمة أولاً')->send();
                                                    $action->halt();
                                                }
                                                
                                                $customer = \App\Models\Customer::find($customerId);
                                                
                                                $waitlist = \App\Models\WaitingList::create([
                                                    'tenant_id' => $customer->tenant_id,
                                                    'customer_name' => $customer->name,
                                                    'customer_phone' => $customer->phone,
                                                    'customer_email' => $customer->email,
                                                    'seats_requested' => $data['seats_requested'],
                                                    'status' => \App\Enums\WaitingListStatusEnum::Pending,
                                                    'notes' => 'تمت الإضافة السريعة من شاشة الحجز',
                                                ]);
                                                
                                                $waitlist->tripInstances()->attach($tripId);
                                                
                                                \Filament\Notifications\Notification::make()->success()->title('تمت الإضافة لقائمة الانتظار!')->send();
                                            })
                                    )
                                    ->afterStateUpdated(function (Forms\Set $set) {
                                        $set('passengers', []);
                                        $set('bookingAddons', []);
                                        $set('package_option_id', null);
                                    })
                                    ->disabledOn('edit'),
                                
                                Forms\Components\Select::make('package_option_id')
                                    ->label('الغرفة / الإقامة')
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
                                    ->hintColor('warning')
                                    ->helperText(fn($state) => $state 
                                        ? 'المقاعد المتبقية: ' . (\App\Models\PackageOption::find($state)?->remaining_seats ?? 'غير محدد')
                                        : null
                                    ),
                                
                                Forms\Components\Select::make('booking_status')
                                    ->label('حالة الحجز')
                                    ->options(function ($record) {
                                        // Define allowed transitions per state
                                        $transitions = [
                                            'pending'           => ['pending', 'confirmed', 'cancelled'],
                                            'confirmed'         => ['confirmed', 'confirmed_partial', 'cancelled'],
                                            'confirmed_partial' => ['confirmed_partial', 'confirmed', 'cancelled'],
                                            'cancelled'         => ['cancelled'],  // Terminal state — no going back
                                        ];
                                
                                        // When creating a new booking, show initial states only
                                        if (!$record) {
                                            return collect(\App\Enums\BookingStatus::cases())
                                                ->filter(fn($s) => in_array($s->value, ['pending', 'confirmed']))
                                                ->mapWithKeys(fn($s) => [$s->value => $s->getLabel()])
                                                ->toArray();
                                        }
                                
                                        $currentValue = $record->booking_status instanceof \App\Enums\BookingStatus
                                            ? $record->booking_status->value
                                            : $record->booking_status;
                                
                                        $allowed = $transitions[$currentValue] ?? [$currentValue];
                                
                                        return collect(\App\Enums\BookingStatus::cases())
                                            ->filter(fn($s) => in_array($s->value, $allowed))
                                            ->mapWithKeys(fn($s) => [$s->value => $s->getLabel()])
                                            ->toArray();
                                    })
                                    ->default(\App\Enums\BookingStatus::Pending)
                                    ->required()
                                    // Bug fix: hidden on create so the field can't be used to
                                    // override what CreateBookingService derives from
                                    // payment_type/deposit logic (an admin could otherwise mark
                                    // a booking "Confirmed" with $0 collected). Editing an
                                    // existing booking's status via the transition state machine
                                    // above is unaffected.
                                    ->hidden(fn ($record) => !$record)
                                    ->helperText(fn($record) => $record ? 'التحولات المتاحة بناءً على الحالة الحالية فقط' : null),
                                
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

                                Forms\Components\TextInput::make('discount_amount')
                                    ->label('خصم إضافي ($)')
                                    ->numeric()
                                    ->default(0)
                                    ->prefix('$')
                                    ->helperText('سيتم خصم هذا المبلغ من الإجمالي النهائي')
                                    ->visibleOn('create'),
                            ])->columns(2),

                        // LABEL-003: Pure Arabic section name
                        Forms\Components\Section::make('بيانات المسافرين')
                            ->schema([
                                Forms\Components\Repeater::make('passengers')
                                    ->relationship()
                                    // VALID-001: Require at least one passenger
                                    ->minItems(1)
                                    ->helperText('يجب إضافة راكب واحد على الأقل لإتمام الحجز')
                                    ->itemActions([
                                        \Filament\Forms\Components\Actions\Action::make('copy_customer')
                                            ->label('نسخ من العميل')
                                            ->icon('heroicon-m-document-duplicate')
                                            ->action(function (Forms\Set $set, Forms\Get $get) {
                                                $customerId = $get('../../customer_id');
                                                if ($customerId) {
                                                    $customer = \App\Models\Customer::find($customerId);
                                                    if ($customer) {
                                                        $parts = explode(' ', trim($customer->name));
                                                        $set('first_name', $parts[0] ?? '');
                                                        $set('last_name', count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : '');
                                                    }
                                                }
                                            }),
                                    ])
                                    ->schema([
                                        // CRIT-003: Replaced non-existent dynamic_data with real Passenger model fields
                                        Forms\Components\TextInput::make('first_name')
                                            ->label('الاسم الأول')
                                            ->nullable()
                                            ->maxLength(255),
                                        Forms\Components\TextInput::make('last_name')
                                            ->label('اسم العائلة')
                                            ->nullable()
                                            ->maxLength(255),
                                        Forms\Components\Select::make('document_type')
                                            ->label('نوع الوثيقة')
                                            ->options([
                                                'national_id' => 'هوية وطنية',
                                                'passport'    => 'جواز سفر',
                                            ])
                                            ->nullable(),
                                        Forms\Components\TextInput::make('document_number')
                                            ->label('رقم الوثيقة')
                                            ->nullable()
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
                                        $total += $packageAdj / 100;

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
                            
                        Forms\Components\Section::make('الدفعة الأولى (عند الحجز)')
                            ->visibleOn('create')
                            ->schema([
                                Forms\Components\TextInput::make('initial_payment_amount')
                                    ->label('المبلغ المستلم الآن')
                                    ->numeric()
                                    ->default(0)
                                    ->minValue(0)
                                    ->prefix('$'),
                                    
                                Forms\Components\Select::make('initial_payment_method')
                                    ->label('طريقة الدفع')
                                    ->options(\App\Enums\PaymentMethodEnum::class)
                                    ->default('cash')
                                    ->required(fn (Forms\Get $get) => $get('initial_payment_amount') > 0)
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
                // Phone booking indicator: shows ⚠️ when any passenger has data_complete=false
                Tables\Columns\IconColumn::make('has_incomplete_passengers')
                    ->label('البيانات')
                    ->getStateUsing(fn ($record) =>
                        $record->passengers->where('data_complete', false)->isNotEmpty()
                    )
                    ->boolean()
                    ->trueIcon('heroicon-o-exclamation-triangle')
                    ->falseIcon('heroicon-o-check-circle')
                    ->trueColor('warning')
                    ->falseColor('success')
                    ->tooltip(fn ($state) => $state ? '⚠️ بيانات الركاب ناقصة — يحتاج متابعة' : 'بيانات مكتملة'),
                Tables\Columns\TextColumn::make('customer.name')
                    ->label('العميل')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('tripInstance.tripTemplate.title')
                    ->label('الرحلة')
                    ->sortable(),
                Tables\Columns\TextColumn::make('booking_status')
                    ->label('حالة الحجز')
                    ->badge()
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
                // Durable, at-a-glance visibility for RequirementPreset gaps — reads the
                // per-passenger requirements_complete flag set by CreateBookingService at
                // creation time (and cleared by CustomerBookingPortal once documents are
                // uploaded), rather than an ephemeral notification staff have to remember.
                // `passengers` is already eager-loaded by getEloquentQuery() above, so this is
                // in-memory, not an extra query per row.
                Tables\Columns\IconColumn::make('requirements_status')
                    ->label('المتطلبات')
                    ->getStateUsing(fn (Booking $record): bool => !$record->passengers->contains(fn ($p) => !$p->requirements_complete))
                    ->boolean()
                    ->trueIcon('heroicon-o-shield-check')
                    ->falseIcon('heroicon-o-shield-exclamation')
                    ->trueColor('success')
                    ->falseColor('danger')
                    ->tooltip(fn (Booking $record): string => $record->passengers->contains(fn ($p) => !$p->requirements_complete)
                        ? 'يوجد مسافر واحد أو أكثر ناقص متطلبات الرحلة'
                        : 'جميع المسافرين مستوفون لمتطلبات الرحلة'),
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
                // MEDIUM-002: Show which staff created this booking
                Tables\Columns\TextColumn::make('user.name')
                    ->label('أنشأه')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('booking_status')
                    ->label('حالة الحجز')
                    ->options(\App\Enums\BookingStatus::class),
                Tables\Filters\SelectFilter::make('trip_instance_id')
                    ->label('الرحلة')
                    ->options(fn () => \App\Models\TripInstance::with('tripTemplate')->get()->mapWithKeys(fn ($i) => [$i->id => $i->tripTemplate->title . ' (' . $i->start_date->format('Y-m-d') . ')']))
                    ->searchable(),
                Tables\Filters\TernaryFilter::make('requirements_status')
                    ->label('متطلبات الرحلة')
                    ->placeholder('الكل')
                    ->trueLabel('جميع المسافرين مستوفون')
                    ->falseLabel('يوجد نقص في المتطلبات')
                    ->queries(
                        true: fn (Builder $query) => $query->whereDoesntHave('passengers', fn (Builder $q) => $q->where('requirements_complete', false)),
                        false: fn (Builder $query) => $query->whereHas('passengers', fn (Builder $q) => $q->where('requirements_complete', false)),
                    ),
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
            ])
            ->groups([
                \Filament\Tables\Grouping\Group::make('tripInstance.tripTemplate.title')
                    ->label('الرحلة')
                    ->collapsible(),
            ])
            ->defaultGroup('tripInstance.tripTemplate.title')
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\ActionGroup::make([
                // ──────────────────────────────────────────────────────────────
                // إضافة مقاعد لحجز موجود — "سارة بدها تضيف 5 أشخاص"
                // ──────────────────────────────────────────────────────────────
                Tables\Actions\Action::make('add_seats')
                    ->label('إضافة مقاعد')
                    ->icon('heroicon-o-plus-circle')
                    ->color('primary')
                    ->visible(fn (Booking $record) =>
                        !in_array($record->booking_status, [\App\Enums\BookingStatus::Cancelled])
                    )
                    ->modalHeading(fn (Booking $record) =>
                        "إضافة مقاعد — {$record->pnr} ({$record->customer?->name})"
                    )
                    ->modalDescription(fn (Booking $record) => implode(' · ', array_filter([
                        $record->tripInstance?->tripTemplate?->title,
                        $record->tripInstance?->start_date?->format('d M Y'),
                        $record->passengers()->count() . ' راكب حالياً',
                        ($record->tripInstance?->remaining_seats ?? 0) . ' مقعد متاح',
                    ])))
                    ->modalWidth('xl')
                    ->form(function (Booking $record): array {
                        $categories = \App\Models\TripPassengerCategory::where(
                            'trip_instance_id', $record->trip_instance_id
                        )->get();

                        if ($categories->isEmpty()) {
                            return [
                                Forms\Components\Placeholder::make('no_categories')
                                    ->label('')
                                    ->content('لا توجد فئات تسعير لهذه الرحلة. الرجاء إضافة فئات من إعدادات الرحلة أولاً.'),
                            ];
                        }

                        $fields = [
                            Forms\Components\Placeholder::make('availability_info')
                                ->label('')
                                ->content(fn () =>
                                    'المقاعد المتاحة حالياً: ' .
                                    ($record->tripInstance?->remaining_seats ?? 0) . ' مقعد'
                                ),
                        ];

                        foreach ($categories as $cat) {
                            $fields[] = Forms\Components\TextInput::make("cat_{$cat->id}")
                                ->label("{$cat->name} — " . number_format($cat->price / 100, 0) . ' $')
                                ->numeric()
                                ->default(0)
                                ->minValue(0)
                                ->maxValue(fn () => $record->tripInstance?->remaining_seats ?? 999)
                                ->suffix('شخص')
                                ->helperText($cat->name . ' / ' . number_format($cat->price / 100, 2) . ' $ للشخص');
                        }

                        return $fields;
                    })
                    ->action(function (array $data, Booking $record): void {
                        $categories = \App\Models\TripPassengerCategory::where(
                            'trip_instance_id', $record->trip_instance_id
                        )->get()->keyBy('id');

                        $totalNewSeats = 0;
                        $specs = [];

                        // Parse submitted counts per category
                        foreach ($data as $key => $count) {
                            if (!str_starts_with($key, 'cat_')) continue;
                            $count = (int) $count;
                            if ($count <= 0) continue;

                            $catId = (int) str_replace('cat_', '', $key);
                            if (!$categories->has($catId)) continue;

                            for ($i = 0; $i < $count; $i++) {
                                $specs[] = ['trip_passenger_category_id' => $catId];
                            }
                            $totalNewSeats += $count;
                        }

                        if ($totalNewSeats === 0) {
                            \Filament\Notifications\Notification::make()
                                ->warning()
                                ->title('لم تحدد أي مقاعد')
                                ->send();
                            return;
                        }

                        // Check remaining inventory
                        $remaining = $record->tripInstance?->remaining_seats ?? 0;
                        if ($totalNewSeats > $remaining) {
                            \Filament\Notifications\Notification::make()
                                ->danger()
                                ->title("لا يوجد مقاعد كافية")
                                ->body("المتاح: {$remaining} مقعد، المطلوب: {$totalNewSeats}")
                                ->send();
                            return;
                        }

                        try {
                            app(\App\Services\BookingService::class)->addPassengers($record, $specs);
                        } catch (\App\Exceptions\InsufficientSeatsException $e) {
                            \Filament\Notifications\Notification::make()
                                ->danger()
                                ->title('لا يوجد مقاعد كافية')
                                ->body($e->getMessage())
                                ->send();
                            return;
                        }

                        \Filament\Notifications\Notification::make()
                            ->success()
                            ->title("✅ تمت إضافة {$totalNewSeats} مقاعد بنجاح")
                            ->body("الحجز {$record->pnr} — الآن يضم " . ($record->passengers()->count()) . " راكب")
                            ->send();
                    }),

                // ── إلغاء ركاب محددين ────────────────────────────────────────
                Tables\Actions\Action::make('cancel_passengers')
                    ->label('إلغاء مقاعد')
                    ->icon('heroicon-o-user-minus')
                    ->color('danger')
                    ->visible(fn (Booking $record) =>
                        $record->booking_status !== \App\Enums\BookingStatus::Cancelled
                        && $record->passengers()->count() > 0
                    )
                    ->modalHeading(fn (Booking $record) =>
                        "إلغاء مقاعد — {$record->pnr} ({$record->customer?->name})"
                    )
                    ->modalDescription('اختر الركاب المراد إلغاء مقاعدهم. المقاعد ستُعاد للمخزون والمبلغ سيُعدَّل.')
                    ->modalWidth('xl')
                    ->form(function (Booking $record): array {
                        $passengers = $record->passengers()->with('tripPassengerCategory')->get();
                        if ($passengers->isEmpty()) {
                            return [\Filament\Forms\Components\Placeholder::make('empty')->label('')->content('لا يوجد ركاب.')];
                        }
                        $options = $passengers->mapWithKeys(function ($p) {
                            $name  = $p->first_name ? trim($p->first_name . ' ' . $p->last_name) : ($p->passenger_label ?? "راكب #{$p->id}");
                            $cat   = $p->tripPassengerCategory?->name ?? '';
                            $price = number_format(($p->price_at_booking ?? 0) / 100, 0);
                            $flag  = !$p->data_complete ? ' ⚠️' : '';
                            return [$p->id => "{$name}{$flag} — {$cat} ({$price} $)"];
                        })->toArray();

                        return [
                            Forms\Components\CheckboxList::make('passenger_ids')
                                ->label('الركاب')
                                ->options($options)
                                ->required()
                                ->minItems(1)
                                ->helperText("الحجز: {$passengers->count()} راكب حالياً"),
                            Forms\Components\Select::make('cancellation_reason')
                                ->label('سبب الإلغاء')
                                ->options([
                                    'customer_request' => 'طلب العميل',
                                    'no_show'          => 'لم يحضر (No Show)',
                                    'medical'          => 'أسباب طبية',
                                    'travel_issue'     => 'مشكلة وثائق / سفر',
                                    'other'            => 'أخرى',
                                ])
                                ->required(),
                            Forms\Components\Textarea::make('cancellation_note')
                                ->label('ملاحظة (اختياري)')->rows(2)->nullable(),
                        ];
                    })
                    ->action(function (array $data, Booking $record): void {
                        $passengers = \App\Models\Passenger::whereIn('id', $data['passenger_ids'] ?? [])
                            ->where('booking_id', $record->id)->get();

                        if ($passengers->isEmpty()) {
                            \Filament\Notifications\Notification::make()->warning()->title('لم يتم العثور على ركاب')->send();
                            return;
                        }

                        $count  = $passengers->count();
                        $amount = $passengers->sum('price_at_booking'); // major currency units (MoneyCast), display only

                        app(\App\Services\BookingService::class)->cancelPassengers(
                            $record,
                            $passengers,
                            $data['cancellation_reason'],
                            $data['cancellation_note'] ?? null
                        );

                        $remaining = \App\Models\Passenger::where('booking_id', $record->id)->count();
                        \Filament\Notifications\Notification::make()
                            ->success()
                            ->title("✅ تم إلغاء {$count} " . ($count === 1 ? 'مقعد' : 'مقاعد'))
                            ->body(
                                ($remaining > 0 ? "الحجز لا يزال نشطاً بـ {$remaining} راكب. " : '⚠️ الحجز أُلغي كلياً. ')
                                . 'المبلغ المُعاد: ' . number_format($amount, 0) . ' $'
                            )
                            ->send();
                    }),

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
                        // P0-6: was previously non-functional — referenced the nonexistent
                        // PaymentStatus::Partial enum case (fatal error). Now delegates to the
                        // canonical service, which correctly transitions Pending -> ConfirmedPartial
                        // via recalculateTotals(), and persists 'status'/'transaction_id' were never
                        // real columns on `payments` (confirmed: no such columns exist in the
                        // schema) — those keys were always silently discarded, not a regression here.
                        app(\App\Services\BookingService::class)->recordPayment(
                            $record,
                            (float) $data['deposit_amount'],
                            'cash',
                            auth()->user(),
                            \App\Enums\PaymentType::DEPOSIT,
                        );
                        \Filament\Notifications\Notification::make()->title('تم تأكيد العربون بنجاح')->success()->send();
                    }),
                    
                Tables\Actions\Action::make('reopen_cancelled')
                    ->label('إعادة فتح الحجز')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('سيتم استعادة الركاب وإعادة حجز المقاعد. سيفشل الإجراء دون أي تغيير إذا لم تعد هناك مقاعد كافية.')
                    ->visible(fn (Booking $record) => $record->booking_status === \App\Enums\BookingStatus::Cancelled && auth()->user()?->hasRole('agency_admin'))
                    ->action(function (Booking $record) {
                        // P0-9: delegates to the canonical service — restores the soft-deleted
                        // passengers cancelBooking() left behind and re-consumes inventory through
                        // the same locked, capacity-checked path CreateBookingService/
                        // transferBooking() use, failing loudly with zero state change if seats
                        // are no longer available instead of silently reopening into an oversold
                        // trip (the prior bare status-flip did neither of these).
                        try {
                            app(\App\Services\BookingService::class)->reopenBooking($record);

                            \Filament\Notifications\Notification::make()
                                ->title('تم إعادة فتح الحجز بنجاح')
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            \Filament\Notifications\Notification::make()
                                ->title('فشلت إعادة فتح الحجز')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
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
                        // P0-6: delegates to the canonical service — 'status'/'transaction_id'/
                        // 'paid_at' were never real columns on `payments` (confirmed: no such
                        // columns exist in the schema), so they were always silently discarded;
                        // recalculateTotals() (via recordPayment()) now owns the
                        // Pending->Confirmed transition instead of this action setting it manually.
                        app(\App\Services\BookingService::class)->recordPayment(
                            $record,
                            (float) $record->grand_total,
                            'cash',
                            auth()->user(),
                            \App\Enums\PaymentType::FULL,
                        );

                        $message = "تم تأكيد استلام الدفعة النقدية بنجاح لحجزك رقم {$record->pnr}. مرفق التذكرة النهائية.";

                        if ($record->customer && $record->customer->phone) {
                            \App\Jobs\SendBookingNotificationJob::dispatch($record, 'whatsapp', $message);
                        }
                        if ($record->customer && $record->customer->email) {
                            \App\Jobs\SendBookingNotificationJob::dispatch($record, 'email', $message);
                        }

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
                        // P0-6: delegates to the canonical service — 'status'/'transaction_id'/
                        // 'paid_at' were never real columns on `payments` (confirmed: no such
                        // columns exist in the schema), so they were always silently discarded;
                        // recalculateTotals() (via recordPayment()) now owns the
                        // ConfirmedPartial->Confirmed transition instead of this action setting
                        // it manually.
                        $balance = (float) $record->balance_due;
                        app(\App\Services\BookingService::class)->recordPayment(
                            $record,
                            $balance,
                            'cash',
                            auth()->user(),
                            \App\Enums\PaymentType::FULL,
                        );

                        $message = "تم استلام الرصيد المتبقي بنجاح وتأكيد حجزك النهائي برقم {$record->pnr}.";

                        if ($record->customer && $record->customer->phone) {
                            \App\Jobs\SendBookingNotificationJob::dispatch($record, 'whatsapp', $message);
                        }
                        if ($record->customer && $record->customer->email) {
                            \App\Jobs\SendBookingNotificationJob::dispatch($record, 'email', $message);
                        }

                        \Filament\Notifications\Notification::make()
                            ->title('تم تحصيل الرصيد بنجاح')
                            ->success()
                            ->send();
                    }),
                    
                Tables\Actions\Action::make('process_cancellation')
                    ->label('إلغاء الحجز بالكامل')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('تأكيد إلغاء الحجز كلياً')
                    ->visible(fn (Booking $record) =>
                        $record->booking_status !== \App\Enums\BookingStatus::Cancelled
                    )
                    ->form([
                        Forms\Components\Select::make('cancellation_reason')
                            ->label('سبب الإلغاء')
                            ->options([
                                'customer_request' => 'طلب العميل',
                                'no_show'          => 'لم يحضر',
                                'medical'          => 'ظروف صحية',
                                'other'            => 'أخرى',
                            ])
                            ->required(),
                        Forms\Components\TextInput::make('cancellation_fee')
                            ->label('رسوم الإلغاء (إن وجدت)')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(fn ($record) => $record->total_paid)
                            ->default(0),
                    ])
                    ->action(function (array $data, Booking $record) {
                        app(\App\Services\BookingService::class)->cancelBooking(
                            $record,
                            $data['cancellation_reason'],
                            (float) $data['cancellation_fee']
                        );

                        \Filament\Notifications\Notification::make()
                            ->title('تم إلغاء الحجز كلياً واستعادة المقاعد')
                            ->success()
                            ->send();
                    }),

                // ── تحويل الحجز لرحلة أخرى ─────────────────────────────────────
                Tables\Actions\Action::make('transfer_booking')
                    ->label('تحويل لرحلة أخرى')
                    ->icon('heroicon-o-arrow-right-on-rectangle')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('تحويل الحجز إلى رحلة أخرى')
                    ->modalDescription('سيتم نقل جميع الركاب للرحلة الجديدة، وإعادة حساب المبلغ الكلي بناءً على الفئات المختارة.')
                    ->visible(fn (Booking $record) =>
                        !in_array($record->booking_status, [\App\Enums\BookingStatus::Cancelled])
                    )
                    ->form([
                        Forms\Components\Select::make('new_trip_instance_id')
                            ->label('الرحلة الجديدة')
                            ->options(function (Booking $record) {
                                return \App\Models\TripInstance::with('tripTemplate')
                                    ->where('id', '!=', $record->trip_instance_id)
                                    ->where('start_date', '>=', now())
                                    ->get()
                                    ->mapWithKeys(function ($t) {
                                        return [$t->id => $t->tripTemplate?->title . ' — ' . $t->start_date?->format('Y-m-d') . ' (' . $t->remaining_seats . ' مقعد متاح)'];
                                    });
                            })
                            ->searchable()
                            ->required()
                            ->live(), // Re-renders the form when changed

                        Forms\Components\Group::make()
                            ->schema(function (Forms\Get $get, Booking $record) {
                                $newTripId = $get('new_trip_instance_id');
                                if (!$newTripId) return [];

                                $categories = \App\Models\TripPassengerCategory::where('trip_instance_id', $newTripId)->get();
                                if ($categories->isEmpty()) {
                                    return [
                                        Forms\Components\Placeholder::make('err')->content('الرحلة المختارة ليس لها فئات تسعير. لا يمكن التحويل.')->label(''),
                                    ];
                                }

                                $options = $categories->mapWithKeys(fn ($c) => [$c->id => $c->name . ' (' . number_format($c->price / 100, 0) . ' $)'])->toArray();

                                $fields = [
                                    Forms\Components\Placeholder::make('lbl')
                                        ->label('')
                                        ->content('يرجى تحديد الفئة الجديدة لكل راكب:'),
                                ];

                                foreach ($record->passengers as $p) {
                                    $name = $p->display_name ?? ($p->first_name ? trim($p->first_name . ' ' . $p->last_name) : ($p->passenger_label ?? "راكب #{$p->id}"));
                                    $fields[] = Forms\Components\Select::make("passenger_{$p->id}_category")
                                        ->label($name)
                                        ->options($options)
                                        ->required();
                                }

                                return $fields;
                            })
                    ])
                    ->action(function (array $data, Booking $record) {
                        $newTripId = $data['new_trip_instance_id'];
                        $newTrip = \App\Models\TripInstance::find($newTripId);
                        $passengers = $record->passengers()->get();

                        $newCategories = \App\Models\TripPassengerCategory::where('trip_instance_id', $newTripId)->get()->keyBy('id');

                        // Build the category map and give friendly (non-locking) feedback for
                        // an incomplete form before ever touching the centralized service.
                        $passengerCategoryMap = [];
                        foreach ($passengers as $p) {
                            $catId = $data["passenger_{$p->id}_category"] ?? null;
                            if (!$catId || !$newCategories->has($catId)) {
                                \Filament\Notifications\Notification::make()->danger()->title('يجب اختيار فئة لكل راكب')->send();
                                return;
                            }
                            $passengerCategoryMap[$p->id] = $catId;
                        }

                        try {
                            app(\App\Services\BookingService::class)->transferBooking($record, $newTrip, $passengerCategoryMap);
                        } catch (\App\Exceptions\InsufficientSeatsException $e) {
                            \Filament\Notifications\Notification::make()->danger()->title('المقاعد المتاحة لا تكفي في الرحلة الجديدة')->send();
                            return;
                        }

                        \Filament\Notifications\Notification::make()
                            ->title('تم تحويل الحجز بنجاح')
                            ->success()
                            ->send();
                    }),
                ])->label('المزيد')->icon('heroicon-m-ellipsis-vertical')->color('gray'),
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
