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
