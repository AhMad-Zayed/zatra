<?php

namespace App\Filament\Resources\TripInstanceResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Grid;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\DeleteAction;

class PackageOptionsRelationManager extends RelationManager
{
    protected static string $relationship = 'packageOptions';
    protected static ?string $title = 'باقات الإقامة';

    public static function getModelLabel(): string
    {
        return 'باقة إقامة';
    }

    public static function getPluralModelLabel(): string
    {
        return 'باقات الإقامة';
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Grid::make(2)->schema([
                TextInput::make('name')
                    ->label('اسم الباقة')
                    ->required()
                    ->placeholder('باقة اقتصادية'),
                TextInput::make('hotel_name')
                    ->label('اسم الفندق')
                    ->nullable()
                    ->placeholder('فندق الريتز كارلتون'),
                Select::make('stars')
                    ->label('عدد النجوم')
                    ->options([
                        1 => '★',
                        2 => '★★',
                        3 => '★★★',
                        4 => '★★★★',
                        5 => '★★★★★',
                    ])
                    ->nullable()
                    ->placeholder('بدون فندق'),
                Select::make('room_type')
                    ->label('نوع الغرفة')
                    ->options(function () {
                        $tenant = \Filament\Facades\Filament::getTenant();
                        $settings = $tenant?->settings ?? [];
                        $options = $settings['room_types'] ?? ['غرفة مفردة', 'غرفة مزدوجة', 'غرفة ثلاثية', 'غرفة رباعية', 'جناح فاخر'];
                        return array_combine($options, $options);
                    })
                    ->searchable()
                    ->createOptionForm([
                        TextInput::make('room_type')
                            ->label('نوع الغرفة الجديد')
                            ->required(),
                    ])
                    ->createOptionUsing(function (array $data) {
                        $newValue = $data['room_type'];
                        $tenant = \Filament\Facades\Filament::getTenant();
                        if ($tenant) {
                            $settings = $tenant->settings ?? [];
                            $roomTypes = $settings['room_types'] ?? ['غرفة مفردة', 'غرفة مزدوجة', 'غرفة ثلاثية', 'غرفة رباعية', 'جناح فاخر'];
                            if (!in_array($newValue, $roomTypes)) {
                                $roomTypes[] = $newValue;
                                $settings['room_types'] = array_values($roomTypes);
                                $tenant->update(['settings' => $settings]);
                            }
                        }
                        return $newValue;
                    })
                    ->getSearchResultsUsing(fn (string $search) => [$search => $search])
                    ->getOptionLabelUsing(fn ($value): ?string => $value)
                    ->createOptionAction(fn (\Filament\Forms\Components\Actions\Action $action) => $action->slideOver())
                    ->hintAction(
                        \Filament\Forms\Components\Actions\Action::make('manageRoomTypes')
                            ->icon('heroicon-m-cog-6-tooth')
                            ->label('إدارة الأنواع')
                            ->slideOver()
                            ->form([
                                \Filament\Forms\Components\Repeater::make('room_types')
                                    ->label('إدارة أنواع الغرف')
                                    ->simple(
                                        \Filament\Forms\Components\TextInput::make('name')->required()
                                    )
                                    ->default(function () {
                                        $tenant = \Filament\Facades\Filament::getTenant();
                                        $settings = $tenant?->settings ?? [];
                                        return $settings['room_types'] ?? ['غرفة مفردة', 'غرفة مزدوجة', 'غرفة ثلاثية', 'غرفة رباعية', 'جناح فاخر'];
                                    })
                                    ->reorderable(true)
                                    ->addActionLabel('إضافة نوع جديد')
                            ])
                            ->action(function (array $data) {
                                $tenant = \Filament\Facades\Filament::getTenant();
                                if ($tenant) {
                                    $settings = $tenant->settings ?? [];
                                    $settings['room_types'] = array_values($data['room_types'] ?? []);
                                    $tenant->update(['settings' => $settings]);
                                }
                            })
                    )
                    ->placeholder('مثال: غرفة مزدوجة')
                    ->nullable(),
                Select::make('meal_plan')
                    ->label('نظام الإطعام / الوجبات')
                    ->options(function () {
                        $tenant = \Filament\Facades\Filament::getTenant();
                        $settings = $tenant?->settings ?? [];
                        $options = $settings['meal_plans'] ?? ['بدون وجبات', 'إفطار فقط', 'إفطار وعشاء', 'نصف إقامة', 'إقامة كاملة', 'كل شيء مشمول'];
                        return array_combine($options, $options);
                    })
                    ->searchable()
                    ->createOptionForm([
                        TextInput::make('meal_plan')
                            ->label('نظام الإطعام الجديد')
                            ->required(),
                    ])
                    ->createOptionUsing(function (array $data) {
                        $newValue = $data['meal_plan'];
                        $tenant = \Filament\Facades\Filament::getTenant();
                        if ($tenant) {
                            $settings = $tenant->settings ?? [];
                            $mealPlans = $settings['meal_plans'] ?? ['بدون وجبات', 'إفطار فقط', 'إفطار وعشاء', 'نصف إقامة', 'إقامة كاملة', 'كل شيء مشمول'];
                            if (!in_array($newValue, $mealPlans)) {
                                $mealPlans[] = $newValue;
                                $settings['meal_plans'] = array_values($mealPlans);
                                $tenant->update(['settings' => $settings]);
                            }
                        }
                        return $newValue;
                    })
                    ->getSearchResultsUsing(fn (string $search) => [$search => $search])
                    ->getOptionLabelUsing(fn ($value): ?string => $value)
                    ->createOptionAction(fn (\Filament\Forms\Components\Actions\Action $action) => $action->slideOver())
                    ->hintAction(
                        \Filament\Forms\Components\Actions\Action::make('manageMealPlans')
                            ->icon('heroicon-m-cog-6-tooth')
                            ->label('إدارة الأنواع')
                            ->slideOver()
                            ->form([
                                \Filament\Forms\Components\Repeater::make('meal_plans')
                                    ->label('إدارة أنظمة الإطعام والوجبات')
                                    ->simple(
                                        \Filament\Forms\Components\TextInput::make('name')->required()
                                    )
                                    ->default(function () {
                                        $tenant = \Filament\Facades\Filament::getTenant();
                                        $settings = $tenant?->settings ?? [];
                                        return $settings['meal_plans'] ?? ['بدون وجبات', 'إفطار فقط', 'إفطار وعشاء', 'نصف إقامة', 'إقامة كاملة', 'كل شيء مشمول'];
                                    })
                                    ->reorderable(true)
                                    ->addActionLabel('إضافة نظام جديد')
                            ])
                            ->action(function (array $data) {
                                $tenant = \Filament\Facades\Filament::getTenant();
                                if ($tenant) {
                                    $settings = $tenant->settings ?? [];
                                    $settings['meal_plans'] = array_values($data['meal_plans'] ?? []);
                                    $tenant->update(['settings' => $settings]);
                                }
                            })
                    )
                    ->placeholder('مثال: إفطار وعشاء')
                    ->nullable(),
                TextInput::make('price_adjustment')
                    ->label('فرق السعر')
                    ->prefix('$')
                    ->numeric()
                    ->default(0)
                    ->helperText('0 = مشمول في سعر الرحلة، 250 = يُضاف 250$ للسعر')
                    ->required(),
                TextInput::make('available_seats')
                    ->label('مقاعد خاصة بالباقة')
                    ->numeric()
                    ->nullable()
                    ->placeholder('اتركه فارغاً لاستخدام سعة الرحلة'),
                TagsInput::make('included_features')
                    ->label('المميزات المشمولة')
                    ->placeholder('نقل من المطار')
                    ->nullable(),
                Textarea::make('description')
                    ->label('وصف الباقة')
                    ->nullable()
                    ->columnSpanFull(),
                Toggle::make('is_active')
                    ->label('متاحة للحجز')
                    ->default(true),
                TextInput::make('sort_order')
                    ->label('الترتيب')
                    ->numeric()
                    ->default(0),
            ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->reorderable('sort_order')
            ->columns([
                TextColumn::make('name')
                    ->label('اسم الباقة')
                    ->sortable(),
                TextColumn::make('hotel_name')
                    ->label('الفندق')
                    ->placeholder('—'),
                TextColumn::make('stars')
                    ->label('النجوم')
                    ->formatStateUsing(fn ($state) => str_repeat('★', $state ?? 0))
                    ->placeholder('—'),
                TextColumn::make('room_type')
                    ->label('نوع الغرفة')
                    ->placeholder('—'),
                TextColumn::make('price_adjustment')
                    ->label('فرق السعر')
                    ->money('USD')
                    ->color(fn ($state) => $state > 0 ? 'warning' : 'success'),
                TextColumn::make('remaining_seats')
                    ->label('المقاعد المتاحة')
                    ->getStateUsing(fn ($record) => $record->remaining_seats)
                    ->badge()
                    ->color(fn ($state) => $state <= 0 ? 'danger' : ($state <= 5 ? 'warning' : 'success')),
                IconColumn::make('is_active')
                    ->label('متاحة')
                    ->boolean(),
            ])
            ->actions([EditAction::make(), DeleteAction::make()])
            ->headerActions([CreateAction::make()->label('إضافة باقة')]);
    }
}
