<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TripInstanceResource\Pages;
use App\Filament\Resources\TripInstanceResource\RelationManagers;
use App\Models\TripInstance;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class TripInstanceResource extends Resource
{
    protected static ?string $model = TripInstance::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    public static function getNavigationLabel(): string
    {
        return 'الرحلات المجدولة';
    }

    public static function getModelLabel(): string
    {
        return 'رحلة مجدولة';
    }

    public static function getPluralModelLabel(): string
    {
        return 'الرحلات المجدولة';
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('المعلومات الأساسية')
                    ->schema([
                        Forms\Components\Select::make('trip_template_id')
                            ->relationship('tripTemplate', 'title')
                            ->label('قالب الرحلة')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->live()
                            ->afterStateUpdated(function (Forms\Set $set, ?string $state) {
                                if (! $state) {
                                    $set('tripPassengerCategories', []);
                                    $set('tripAddons', []);
                                    return;
                                }

                                $template = \App\Models\TripTemplate::with(['templatePassengerCategories', 'templateAddons'])->find($state);

                                if ($template) {
                                    $tiers = $template->templatePassengerCategories->map(fn ($tier) => [
                                        'name' => $tier->name,
                                        'price' => $tier->price,
                                    ])->toArray();

                                    $addons = $template->templateAddons->map(fn ($addon) => [
                                        'name' => $addon->name,
                                        'price' => $addon->price,
                                        'max_quantity' => $addon->max_quantity,
                                    ])->toArray();

                                    $set('tripPassengerCategories', $tiers);
                                    $set('tripAddons', $addons);
                                }
                            }),
                        Forms\Components\DatePicker::make('start_date')
                            ->label('تاريخ الذهاب')
                            ->required(),
                        Forms\Components\DatePicker::make('end_date')
                            ->label('تاريخ الإياب')
                            ->required(),
                        Forms\Components\TextInput::make('available_seats')
                            ->label('المقاعد المتاحة')
                            ->numeric()
                            ->required(),
                        Forms\Components\Select::make('status')
                            ->label('حالة الرحلة')
                            ->options([
                                'active' => 'نشط',
                                'completed' => 'مكتملة',
                                'cancelled' => 'ملغية',
                            ])
                            ->required()
                            ->default('active'),
                    ])->columns(2),

                Forms\Components\Section::make('فئات التسعير الخاصة بهذا الموعد')
                    ->description('تم نسخ هذه الفئات من القالب تلقائياً، يمكنك تعديل أسعارها لهذا الموعد خصيصاً (مثال: أسعار العطلات).')
                    ->schema([
                        Forms\Components\Repeater::make('tripPassengerCategories')
                            ->relationship()
                            ->label('الفئات')
                            ->schema([
                                Forms\Components\TextInput::make('name')
                                    ->label('اسم الفئة')
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('price')
                                    ->label('السعر لهذا الموعد')
                                    ->numeric()
                                    ->required()
                                    ->prefix('$'),
                            ])
                            ->columns(2)
                            ->addActionLabel('إضافة فئة تسعير استثنائية'),
                    ]),

                Forms\Components\Section::make('الإضافات والمخزون الخاص بهذا الموعد')
                    ->description('تم نسخ الإضافات من القالب، قم بتحديد السعة القصوى المتاحة (Inventory) لهذا الموعد لتجنب الحجوزات الزائدة.')
                    ->schema([
                        Forms\Components\Repeater::make('tripAddons')
                            ->relationship()
                            ->label('الإضافات')
                            ->schema([
                                Forms\Components\TextInput::make('name')
                                    ->label('اسم الإضافة')
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('price')
                                    ->label('السعر')
                                    ->numeric()
                                    ->required()
                                    ->prefix('$'),
                                Forms\Components\TextInput::make('max_quantity')
                                    ->label('السعة القصوى المتاحة')
                                    ->numeric()
                                    ->helperText('مثال: 5 غرف مفردة فقط في هذا التاريخ'),
                            ])
                            ->columns(3)
                            ->addActionLabel('إضافة خدمة استثنائية'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('tripTemplate.title')
                    ->label('الرحلة')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('start_date')
                    ->label('تاريخ الذهاب')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('end_date')
                    ->label('تاريخ الإياب')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('available_seats')
                    ->label('المقاعد المتاحة')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->colors([
                        'primary' => 'active',
                        'success' => 'completed',
                        'danger' => 'cancelled',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'active' => 'نشط',
                        'completed' => 'مكتملة',
                        'cancelled' => 'ملغية',
                        default => $state,
                    })
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
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
            \App\Filament\Resources\TripInstanceResource\RelationManagers\WaitingListsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTripInstances::route('/'),
            'create' => Pages\CreateTripInstance::route('/create'),
            'edit' => Pages\EditTripInstance::route('/{record}/edit'),
        ];
    }
}
