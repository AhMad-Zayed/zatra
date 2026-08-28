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

    public static function getModelLabel(): string
    {
        return 'رحلة مجدولة';
    }

    public static function getPluralModelLabel(): string
    {
        return 'الرحلات المجدولة';
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
                    ->required()
                    // Bus/Fleet redesign Ticket 2: same lock as TripInstanceResource's own
                    // form — once a trip has bus assignments, available_seats is a managed
                    // value kept in sync by TripFleetService, not hand-edited here.
                    ->disabled(fn (?\App\Models\TripInstance $record): bool => $record !== null
                        && app(\App\Services\TripFleetService::class)->hasAnyBusAssignments($record))
                    ->helperText(fn (?\App\Models\TripInstance $record): ?string => ($record !== null
                        && app(\App\Services\TripFleetService::class)->hasAnyBusAssignments($record))
                        ? 'هذه الرحلة تستخدم إدارة الأسطول — السعة محسوبة تلقائياً من الحافلات المخصصة.'
                        : null),
                Forms\Components\Select::make('status')
                    ->label('الحالة')
                    ->options([
                        'draft' => 'مسودة',
                        'active' => 'فعال',
                        'completed' => 'مكتمل',
                    ])
                    ->required()
                    ->default('active'),

                // Package-option (hotel/room/meal-plan) editing was previously duplicated here
                // AND as a standalone PackageOptionsRelationManager on TripInstanceResource —
                // two screens editing the same PackageOption records with near-identical forms.
                // PackageOptionsRelationManager is now the single source of truth; manage a
                // trip instance's package options from its own edit page instead.
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
                    // Pre-existing bug fix (unrelated to this ticket's items, but it crashes
                    // this table's render outright whenever a row exists): status is cast to
                    // TripStatusEnum on the model, not a plain string — the type hint here
                    // rejected every real value. Unwrapped the same way TripInstanceResource's
                    // own status column already does.
                    ->color(fn ($state): string => match ($state instanceof \App\Enums\TripStatusEnum ? $state->value : $state) {
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
                        // Bug fix: currency was never set here, silently relying on the DB
                        // column default ('USD') regardless of the template's actual currency.
                        $data['currency'] = $this->getOwnerRecord()->currency;
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
                    ->action(function (array $data) {
                        $ownerRecord = $this->getOwnerRecord();
                        $startDate = \Carbon\Carbon::parse($data['start_date_range']);
                        $endDate = \Carbon\Carbon::parse($data['end_date_range']);
                        $daysOfWeek = $data['days_of_week'];
                        $duration = $data['duration_days'] - 1;

                        $currentDate = $startDate->copy();

                        while ($currentDate->lte($endDate)) {
                            if (in_array($currentDate->dayOfWeek, $daysOfWeek)) {
                                $instance = $ownerRecord->tripInstances()->create([
                                    'tenant_id' => $ownerRecord->tenant_id,
                                    // Bug fix: currency was never set here either, same root
                                    // cause as the manual CreateAction above.
                                    'currency' => $ownerRecord->currency,
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
