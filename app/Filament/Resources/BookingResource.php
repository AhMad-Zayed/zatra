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
                                    ->getOptionLabelFromRecordUsing(fn (Model $record) => "{$record->name} - {$record->phone}")
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
                                    ->mutateRelationshipDataBeforeCreateUsing(function (array $data): array {
                                        $data['tenant_id'] = \Filament\Facades\Filament::getTenant()?->id ?? auth()->user()->tenants()->first()->id;
                                        return $data;
                                    })
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
                            ])->columns(2),

                        Forms\Components\Section::make('المسافرون (Passengers)')
                            ->schema([
                                Forms\Components\Repeater::make('passengers')
                                    ->relationship()
                                    ->schema([
                                        Forms\Components\Select::make('trip_pricing_tier_id')
                                            ->label('فئة التسعير')
                                            ->options(function (Forms\Get $get) {
                                                $instanceId = $get('../../trip_instance_id');
                                                if (!$instanceId) return [];
                                                return \App\Models\TripPricingTier::where('trip_instance_id', $instanceId)->pluck('name', 'id');
                                            })
                                            ->required()
                                            ->live()
                                            ->afterStateUpdated(function (Forms\Set $set, ?string $state) {
                                                if ($state) {
                                                    $tier = \App\Models\TripPricingTier::find($state);
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
                Tables\Columns\TextColumn::make('user.name')
                    ->label('العميل')
                    ->sortable(),
                Tables\Columns\TextColumn::make('tripInstance.tripTemplate.title')
                    ->label('الرحلة')
                    ->sortable(),
                Tables\Columns\TextColumn::make('booking_status')
                    ->label('حالة الحجز')
                    ->badge(),
                Tables\Columns\TextColumn::make('payment_status')
                    ->label('حالة الدفع')
                    ->badge(),
                Tables\Columns\TextColumn::make('balance_due')
                    ->label('المتبقي')
                    ->money('USD')
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->actions([
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
