<?php

namespace App\Filament\Resources\TripTemplateResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class TripInstancesRelationManager extends RelationManager
{
    protected static string $relationship = 'tripInstances';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return 'مواعيد الرحلات المجدولة';
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\DatePicker::make('start_date')
                    ->label('تاريخ البداية')
                    ->required(),
                Forms\Components\DatePicker::make('end_date')
                    ->label('تاريخ النهاية')
                    ->required(),
                Forms\Components\TextInput::make('available_seats')
                    ->label('المقاعد المتاحة')
                    ->numeric()
                    ->required(),
                Forms\Components\Select::make('status')
                    ->label('الحالة')
                    ->options([
                        'draft' => 'مسودة',
                        'active' => 'فعال',
                        'completed' => 'مكتمل',
                        'cancelled' => 'ملغي',
                    ])
                    ->required()
                    ->default('active'),
                    
                Forms\Components\Repeater::make('packageOptions')
                    ->relationship('packageOptions')
                    ->label('باقات الإقامة (اختياري)')
                    ->schema([
                        Forms\Components\Grid::make(2)->schema([
                            Forms\Components\TextInput::make('name')
                                ->label('اسم الباقة')
                                ->required()
                                ->placeholder('باقة اقتصادية'),
                            Forms\Components\TextInput::make('hotel_name')
                                ->label('اسم الفندق')
                                ->nullable(),
                            Forms\Components\Select::make('stars')
                                ->label('عدد النجوم')
                                ->options([1 => '★', 2 => '★★', 3 => '★★★', 4 => '★★★★', 5 => '★★★★★'])
                                ->nullable(),
                            Forms\Components\Select::make('room_type')
                                ->label('نوع الغرفة')
                                ->options(function () {
                                    $tenant = \Filament\Facades\Filament::getTenant();
                                    $settings = $tenant?->settings ?? [];
                                    $options = $settings['room_types'] ?? ['غرفة مفردة', 'غرفة مزدوجة', 'غرفة ثلاثية', 'غرفة رباعية', 'جناح فاخر'];
                                    return array_combine($options, $options);
                                })
                                ->searchable()
                                ->createOptionForm([
                                    Forms\Components\TextInput::make('room_type')
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
                            Forms\Components\Select::make('meal_plan')
                                ->label('الوجبات / نظام الإطعام')
                                ->options(function () {
                                    $tenant = \Filament\Facades\Filament::getTenant();
                                    $settings = $tenant?->settings ?? [];
                                    $options = $settings['meal_plans'] ?? ['بدون وجبات', 'إفطار فقط', 'إفطار وعشاء', 'نصف إقامة', 'إقامة كاملة', 'كل شيء مشمول'];
                                    return array_combine($options, $options);
                                })
                                ->searchable()
                                ->createOptionForm([
                                    Forms\Components\TextInput::make('meal_plan')
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
                            Forms\Components\TextInput::make('price_adjustment')
                                ->label('فرق السعر (بالسنت)')
                                ->numeric()
                                ->default(0)
                                ->required(),
                            Forms\Components\TextInput::make('available_seats')
                                ->label('مقاعد الباقة')
                                ->numeric()
                                ->nullable(),
                            Forms\Components\Toggle::make('is_active')
                                ->label('متاحة')
                                ->default(true),
                        ])
                    ])
                    ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
                    ->collapsible()
                    ->collapsed()
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('start_date')
            ->heading('مواعيد الرحلات المجدولة')
            ->columns([
                Tables\Columns\TextColumn::make('start_date')
                    ->label('تاريخ البداية')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('end_date')
                    ->label('تاريخ النهاية')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('available_seats')
                    ->label('المقاعد المتاحة')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('remaining_seats')
                    ->label('المقاعد المتبقية')
                    ->badge()
                    ->color(fn ($state): string => match (true) {
                        $state <= 0 => 'danger',
                        $state <= 5 => 'warning',
                        default => 'success',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'draft' => 'gray',
                        'active' => 'success',
                        'completed' => 'info',
                        'cancelled' => 'danger',
                        default => 'primary',
                    }),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('إضافة موعد جديد')
                    ->mutateFormDataUsing(function (array $data): array {
                        if (auth()->check()) {
                            $data['tenant_id'] = \Filament\Facades\Filament::getTenant()?->id;
                        }
                        return $data;
                    }),
                Tables\Actions\Action::make('bulk_schedule')
                    ->label('جدولة متكررة (Bulk)')
                    ->icon('heroicon-o-calendar-days')
                    ->form([
                        Forms\Components\DatePicker::make('start_date_range')
                            ->label('بداية الفترة')
                            ->required(),
                        Forms\Components\DatePicker::make('end_date_range')
                            ->label('نهاية الفترة')
                            ->required(),
                        Forms\Components\Select::make('days_of_week')
                            ->label('أيام الأسبوع')
                            ->multiple()
                            ->options([
                                1 => 'الاثنين',
                                2 => 'الثلاثاء',
                                3 => 'الأربعاء',
                                4 => 'الخميس',
                                5 => 'الجمعة',
                                6 => 'السبت',
                                0 => 'الأحد',
                            ])
                            ->required(),
                        Forms\Components\TextInput::make('duration_days')
                            ->label('مدة الرحلة (بالأيام)')
                            ->numeric()
                            ->required()
                            ->default(1),
                        Forms\Components\TextInput::make('available_seats')
                            ->label('المقاعد المتاحة لكل رحلة')
                            ->numeric()
                            ->required(),
                    ])
                    ->action(function (array $data, \Illuminate\Database\Eloquent\Model $ownerRecord) {
                        $startDate = \Carbon\Carbon::parse($data['start_date_range']);
                        $endDate = \Carbon\Carbon::parse($data['end_date_range']);
                        $daysOfWeek = $data['days_of_week'];
                        $duration = $data['duration_days'] - 1;

                        $currentDate = $startDate->copy();

                        while ($currentDate->lte($endDate)) {
                            if (in_array($currentDate->dayOfWeek, $daysOfWeek)) {
                                $instance = $ownerRecord->tripInstances()->create([
                                    'tenant_id' => $ownerRecord->tenant_id,
                                    'start_date' => $currentDate->copy(),
                                    'end_date' => $currentDate->copy()->addDays($duration),
                                    'available_seats' => $data['available_seats'],
                                    'status' => 'active',
                                ]);

                                foreach ($ownerRecord->templatePassengerCategories as $tier) {
                                    $instance->tripPassengerCategories()->create([
                                        'tenant_id' => $ownerRecord->tenant_id,
                                        'name' => $tier->name,
                                        'price' => $tier->price,
                                        'requires_seat' => $tier->requires_seat,
                                    ]);
                                }

                                foreach ($ownerRecord->templateAddons as $addon) {
                                    $instance->tripAddons()->create([
                                        'tenant_id' => $ownerRecord->tenant_id,
                                        'name' => $addon->name,
                                        'price' => $addon->price,
                                        'max_quantity' => $addon->max_quantity,
                                    ]);
                                }
                            }
                            $currentDate->addDay();
                        }
                    }),
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
}
