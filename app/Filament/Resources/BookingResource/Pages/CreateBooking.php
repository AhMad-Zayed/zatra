<?php

namespace App\Filament\Resources\BookingResource\Pages;

use App\Filament\Resources\BookingResource;
use App\Models\PackageOption;
use App\Models\PickupPoint;
use App\Models\TripInstance;
use App\Models\TripPassengerCategory;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Components\Wizard\Step;
use Filament\Resources\Pages\CreateRecord;
use App\Services\CreateBookingService;
use App\Exceptions\InventoryExhaustedException;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A guided, step-validated wizard — consolidating what used to be two separate flows
 * (this page's old single long form, and the now-retired QuickBookingPage) into one. Each
 * Step's fields are duplicated from — not extracted/shared with — BookingResource::form()
 * (still the flat form EditBooking and the relation managers use) deliberately: this is a
 * live booking-creation form, and refactoring the two into shared helpers risked a subtle
 * extraction bug reaching a production financial flow for zero functional benefit. Field
 * definitions, relative Get()/Set() paths, and validation rules below are unchanged from
 * BookingResource::form() — only the grouping (flat -> steps) and presentation differ.
 *
 * Per-step validation is Filament's own built-in Wizard behavior (validates the current
 * step's fields before "next" advances) — no custom validation code needed here.
 */
class CreateBooking extends CreateRecord
{
    use \Filament\Resources\Pages\CreateRecord\Concerns\HasWizard;

    protected static string $resource = BookingResource::class;

    public function getSteps(): array
    {
        return [
            Step::make('customer')
                ->label('العميل')
                ->icon('heroicon-o-user')
                ->schema([
                    Forms\Components\Select::make('customer_id')
                        ->relationship(
                            name: 'customer',
                            titleAttribute: 'phone',
                            modifyQueryUsing: fn (Builder $query) => $query->where('tenant_id', \Filament\Facades\Filament::getTenant()?->id ?? auth()->user()->tenants()->first()->id)
                        )
                        ->getOptionLabelFromRecordUsing(fn (Model $record) => "{$record->name} - {$record->phone}")
                        ->label('العميل الرئيسي')
                        // Search both name and phone — bare ->searchable() on a relationship
                        // select only searches the titleAttribute ('phone'), so staff couldn't
                        // find a customer by typing their name.
                        ->searchable(['name', 'phone'])
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
                        ->createOptionAction(fn (Forms\Components\Actions\Action $action) => $action->mutateFormDataUsing(function (array $data): array {
                            $data['tenant_id'] = \Filament\Facades\Filament::getTenant()?->id ?? auth()->user()->tenants()->first()->id;
                            return $data;
                        })),
                ]),

            Step::make('trip')
                ->label('الرحلة')
                ->icon('heroicon-o-map')
                ->schema([
                    Forms\Components\Select::make('trip_instance_id')
                        ->label('موعد الرحلة')
                        ->options(function () {
                            return TripInstance::with('tripTemplate')
                                ->where('start_date', '>=', now()->subDays(1))
                                ->orderBy('start_date', 'asc')
                                ->get()
                                ->mapWithKeys(function ($instance) {
                                    return [$instance->id => $instance->tripTemplate->title . ' (' . $instance->start_date->format('Y-m-d') . ')'];
                                })
                                ->toArray();
                        })
                        ->searchable()
                        ->preload()
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
                            Forms\Components\Actions\Action::make('add_to_waitlist')
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
                                ->action(function (array $data, Forms\Get $get, Forms\Components\Actions\Action $action) {
                                    $tripId = $get('trip_instance_id');
                                    $customerId = $get('customer_id');

                                    if (!$customerId) {
                                        Notification::make()->danger()->title('الرجاء اختيار العميل من القائمة أولاً')->send();
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

                                    Notification::make()->success()->title('تمت الإضافة لقائمة الانتظار!')->send();
                                })
                        )
                        ->afterStateUpdated(function (Forms\Set $set) {
                            $set('passengers', []);
                            $set('bookingAddons', []);
                            $set('package_option_id', null);
                        }),
                ]),

            Step::make('passengers')
                ->label('المسافرون')
                ->icon('heroicon-o-users')
                ->schema([
                    Forms\Components\Repeater::make('passengers')
                        ->relationship()
                        ->label('')
                        ->addActionLabel('إضافة راكب')
                        ->minItems(1)
                        ->helperText('يجب إضافة راكب واحد على الأقل لإتمام الحجز')
                        ->extraItemActions([
                            Forms\Components\Actions\Action::make('copy_customer')
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
                                    'passport' => 'جواز سفر',
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
                                    return TripPassengerCategory::where('trip_instance_id', $instanceId)->pluck('name', 'id')->toArray();
                                })
                                ->required()
                                ->live()
                                ->afterStateUpdated(function (Forms\Set $set, ?string $state) {
                                    if ($state) {
                                        $tier = TripPassengerCategory::find($state);
                                        if ($tier) {
                                            $set('unit_price', round($tier->price / 100, 2));
                                        }
                                    }
                                }),
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
                                    )->pluck('name', 'id')->toArray();
                                })
                                ->nullable(),
                        ])
                        ->columns(3)
                        ->live(),
                ]),

            Step::make('package')
                ->label('الإقامة')
                ->icon('heroicon-o-building-office-2')
                ->schema([
                    Forms\Components\Select::make('package_option_id')
                        ->label('الغرفة / الإقامة')
                        ->options(fn (Forms\Get $get): array =>
                            PackageOption::where('trip_instance_id', $get('trip_instance_id'))
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
                        ->hint(fn (Forms\Get $get): ?string =>
                            $get('package_option_id')
                                ? '$' . number_format(
                                    PackageOption::find($get('package_option_id'))?->price_adjustment ?? 0,
                                    2
                                  ) . ' إضافي على سعر الرحلة'
                                : null
                        )
                        ->hintColor('warning')
                        ->helperText(fn ($state) => $state
                            ? 'المقاعد المتبقية: ' . (PackageOption::find($state)?->remaining_seats ?? 'غير محدد')
                            : 'إن لم تحتوِ الرحلة على خيارات إقامة، تخطَّ هذه الخطوة.'
                        ),
                ]),

            Step::make('addons')
                ->label('الخدمات الإضافية')
                ->icon('heroicon-o-plus-circle')
                ->schema([
                    Forms\Components\Repeater::make('bookingAddons')
                        ->relationship()
                        ->label('')
                        ->addActionLabel('إضافة خدمة')
                        ->schema([
                            Forms\Components\Select::make('passenger_id')
                                ->label('المسافر (اختياري)')
                                ->options(function (Forms\Get $get) {
                                    $passengers = $get('../../passengers') ?? [];
                                    $options = [];
                                    foreach ($passengers as $key => $p) {
                                        $name = ($p['first_name'] ?? 'مسافر') . ' ' . ($p['last_name'] ?? '');
                                        $options[$key] = $name;
                                    }
                                    $bookingId = $get('../../id');
                                    if ($bookingId) {
                                        return \App\Models\Passenger::where('booking_id', $bookingId)
                                            ->get()
                                            ->mapWithKeys(fn ($p) => [$p->id => $p->first_name . ' ' . $p->last_name])
                                            ->toArray();
                                    }
                                    return [];
                                })
                                ->searchable(),
                            Forms\Components\Select::make('trip_addon_id')
                                ->label('الإضافة')
                                ->options(function (Forms\Get $get) {
                                    $instanceId = $get('../../trip_instance_id');
                                    if (!$instanceId) return [];
                                    return \App\Models\TripAddon::where('trip_instance_id', $instanceId)->pluck('name', 'id')->toArray();
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
                                }),
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
                                }),
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
                        ->live(),
                ]),

            Step::make('payment')
                ->label('الدفع')
                ->icon('heroicon-o-banknotes')
                ->schema([
                    Forms\Components\TextInput::make('discount_amount')
                        ->label('خصم إضافي ($)')
                        ->numeric()
                        ->default(0)
                        ->prefix('$')
                        ->helperText('سيتم خصم هذا المبلغ من الإجمالي النهائي'),
                    Forms\Components\DateTimePicker::make('expires_at')
                        ->label('موعد انتهاء مهلة الدفع')
                        ->default(now()->addHours(24))
                        ->helperText('سيتم إلغاء الحجز تلقائياً إذا لم يُسدَّد المبلغ قبل هذا الوقت')
                        ->required()
                        ->native(false),
                    Forms\Components\TextInput::make('initial_payment_amount')
                        ->label('المبلغ المستلم الآن')
                        ->numeric()
                        ->default(0)
                        ->minValue(0)
                        ->prefix('$')
                        ->live(),
                    Forms\Components\Select::make('initial_payment_method')
                        ->label('طريقة الدفع')
                        ->options([
                            'cash' => 'نقدي',
                            'bank_transfer' => 'تحويل بنكي',
                        ])
                        ->default('cash')
                        ->required(fn (Forms\Get $get) => $get('initial_payment_amount') > 0),
                ])
                ->columns(2),

            Step::make('confirmation')
                ->label('التأكيد')
                ->icon('heroicon-o-check-circle')
                ->schema([
                    Forms\Components\Section::make('ملخص الحجز')
                        ->schema([
                            Forms\Components\Placeholder::make('summary_customer')
                                ->label('العميل')
                                ->content(fn (Forms\Get $get): string =>
                                    \App\Models\Customer::find($get('customer_id'))?->name ?? '—'
                                ),
                            Forms\Components\Placeholder::make('summary_trip')
                                ->label('الرحلة')
                                ->content(fn (Forms\Get $get): string =>
                                    TripInstance::find($get('trip_instance_id'))?->tripTemplate?->title ?? '—'
                                ),
                            Forms\Components\Placeholder::make('summary_passengers')
                                ->label('عدد المسافرين')
                                ->content(fn (Forms\Get $get): string =>
                                    (string) count($get('passengers') ?? [])
                                ),
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
                                            $total += (float) ($a['total_price'] ?? 0);
                                        }
                                    }

                                    $packageAdj = PackageOption::find($get('package_option_id'))?->price_adjustment ?? 0;
                                    $total += $packageAdj / 100;

                                    return '$' . number_format($total, 2);
                                }),
                        ])
                        ->columns(2),
                    Forms\Components\Textarea::make('notes')
                        ->label('ملاحظات الحجز')
                        ->rows(3),
                ]),
        ];
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // 1. Silently inject the Audit Trail (Creator Admin ID)
        $data['user_id'] = auth()->id();

        // 2. Silently inject the Tenant ID (Admin context)
        $data['tenant_id'] = \Filament\Facades\Filament::getTenant()?->id ?? auth()->user()->tenants()->first()->id;

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        $service = new CreateBookingService();

        // Format Passengers Payload
        $passengersData = [];
        if (isset($data['passengers'])) {
            foreach ($data['passengers'] as $p) {
                $passengersData[] = [
                    'trip_passenger_category_id' => $p['trip_passenger_category_id'],
                    'first_name' => $p['first_name'] ?? null,
                    'last_name' => $p['last_name'] ?? null,
                    'document_type' => $p['document_type'] ?? null,
                    'document_number' => $p['document_number'] ?? null,
                    'date_of_birth' => $p['date_of_birth'] ?? null,
                    'gender' => $p['gender'] ?? null,
                    'pickup_point_id' => $p['pickup_point_id'] ?? null,
                ];
            }
        }
        $data['passengersData'] = $passengersData;

        // Format Addons Payload
        $addonsData = [];
        if (isset($data['bookingAddons'])) {
            foreach ($data['bookingAddons'] as $a) {
                $addonsData[] = [
                    'trip_addon_id' => $a['trip_addon_id'],
                    'quantity' => $a['quantity'],
                ];
            }
        }
        $data['addonsData'] = $addonsData;

        try {
            // Pass the UNIFIED payload array to the refactored Service
            $booking = $service->execute($data);

            // Bug fix: this used to let the admin form's booking_status field silently
            // override whatever CreateBookingService::execute() had just derived from
            // payment_type/deposit logic — an admin could mark a booking "Confirmed" with $0
            // collected. booking_status is now left exactly as the service (and the payment
            // observer chain) derives it, same as every other booking-creation path.

            // Permissive requirement-preset check: never blocks admin-created bookings, but
            // surfaces a warning so the creating admin knows documentation is still needed.
            // CreateBookingService::execute() already computed and persisted each passenger's
            // requirements_complete flag.
            if ($summary = app(\App\Services\RequirementValidationService::class)->summarizeIncompletePassengers($booking)) {
                Notification::make()
                    ->warning()
                    ->title('تنبيه: بيانات ناقصة')
                    ->body($summary)
                    ->persistent()
                    ->send();
            }

            return $booking;

        } catch (InventoryExhaustedException $e) {
            Notification::make()
                ->danger()
                ->title('فشل الحجز (عذراً نفذت الكمية)')
                ->body($e->getMessage())
                ->send();

            $this->halt();
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }

    protected function afterCreate(): void
    {
        $booking = $this->record;

        // Dispatch the job to send the magic link
        \App\Jobs\SendAtlahubWhatsAppJob::dispatch(
            $booking->tenant_id,
            'magic_link',
            [
                'phone_number' => $booking->customer->phone,
                'customer_name' => $booking->customer->name,
                'custom_attributes' => [
                    'last_destination' => $booking->tripInstance->tripTemplate->title,
                    'booking_status' => $booking->booking_status->value,
                    'total_paid' => $booking->payments->sum('amount')->getAmount() / 100, // assuming MoneyCast logic
                ],
                'template_variables' => [
                    $booking->customer->name,
                    route('customer.booking.portal', $booking->uuid) // Magic link
                ]
            ]
        );
    }
}
