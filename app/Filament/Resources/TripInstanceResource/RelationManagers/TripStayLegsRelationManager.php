<?php

namespace App\Filament\Resources\TripInstanceResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Hotel/Rooming redesign Phase 1 — data model + admin CRUD only. Deliberately independent of
 * PackageOptionsRelationManager, which remains fully untouched and live; nothing here consumes
 * or affects room inventory yet (Ticket 2) and there is no rooming list yet (Ticket 3).
 *
 * Structural note: the "Legs -> Hotel Options -> Room Types" hierarchy is implemented as ONE
 * relation manager (this one) with two levels of nested Repeater::relationship(), not as three
 * literally-nested Filament RelationManagers. Filament v3 does not support registering a
 * RelationManager inside another RelationManager's row — RelationManagers attach only to a
 * Resource's own Edit/View page. Three separate drill-down Resources (Leg -> HotelOption ->
 * RoomType) would be the alternative, but that's a materially bigger navigational UI than what
 * "data model + admin CRUD only" calls for; nested relationship repeaters achieve the same
 * one-screen management capability Filament actually supports at this depth.
 */
class TripStayLegsRelationManager extends RelationManager
{
    protected static string $relationship = 'tripStayLegs';
    protected static ?string $title = 'مراحل الإقامة';

    public static function getModelLabel(): string
    {
        return 'مرحلة إقامة';
    }

    public static function getPluralModelLabel(): string
    {
        return 'مراحل الإقامة';
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Grid::make(2)->schema([
                    Forms\Components\TextInput::make('sequence')
                        ->label('الترتيب')
                        ->numeric()
                        ->default(1)
                        ->required(),
                    Forms\Components\TextInput::make('label')
                        ->label('اسم المرحلة (مثال: إسطنبول)')
                        ->nullable()
                        ->maxLength(255),
                    Forms\Components\DatePicker::make('start_date')
                        ->label('تاريخ البداية')
                        ->required(),
                    Forms\Components\DatePicker::make('end_date')
                        ->label('تاريخ النهاية')
                        ->required()
                        ->afterOrEqual('start_date'),
                ]),

                Forms\Components\Repeater::make('hotelOptions')
                    ->relationship('hotelOptions')
                    ->label('خيارات الفنادق لهذه المرحلة')
                    ->mutateRelationshipDataBeforeCreateUsing(function (array $data): array {
                        $data['tenant_id'] = \Filament\Facades\Filament::getTenant()?->id;
                        return $data;
                    })
                    ->schema([
                        Forms\Components\Grid::make(3)->schema([
                            Forms\Components\Select::make('hotel_id')
                                ->label('الفندق')
                                ->options(fn () => \App\Models\Hotel::where('tenant_id', \Filament\Facades\Filament::getTenant()?->id)
                                    ->where('is_active', true)
                                    ->orderBy('name')
                                    ->pluck('name', 'id'))
                                ->searchable()
                                ->required(),
                            Forms\Components\TextInput::make('label')
                                ->label('اسم الخيار (مثال: الخيار الاقتصادي)')
                                ->nullable()
                                ->maxLength(255),
                            Forms\Components\TextInput::make('meal_plan')
                                ->label('نظام الإطعام')
                                ->nullable()
                                ->maxLength(255),
                            Forms\Components\Toggle::make('is_active')
                                ->label('متاح')
                                ->default(true),
                            Forms\Components\TextInput::make('sort_order')
                                ->label('الترتيب')
                                ->numeric()
                                ->default(0),
                        ]),

                        Forms\Components\Repeater::make('roomTypes')
                            ->relationship('roomTypes')
                            ->label('أنواع الغرف لهذا الخيار')
                            ->mutateRelationshipDataBeforeCreateUsing(function (array $data): array {
                                $data['tenant_id'] = \Filament\Facades\Filament::getTenant()?->id;
                                return $data;
                            })
                            ->schema([
                                Forms\Components\Grid::make(3)->schema([
                                    Forms\Components\TextInput::make('name')
                                        ->label('نوع الغرفة (مفردة/مزدوجة/...)')
                                        ->required()
                                        ->maxLength(255),
                                    Forms\Components\TextInput::make('capacity_per_room')
                                        ->label('سعة الغرفة (أشخاص)')
                                        ->numeric()
                                        ->minValue(1)
                                        ->required(),
                                    Forms\Components\TextInput::make('room_count')
                                        ->label('عدد الغرف المتاحة')
                                        ->numeric()
                                        ->minValue(0)
                                        ->required()
                                        ->helperText('العدد الفعلي للغرف، وليس عدد الأشخاص'),
                                    Forms\Components\TextInput::make('price_adjustment_shared')
                                        ->label('فرق السعر (بالمشاركة الكاملة، للشخص)')
                                        ->numeric()
                                        ->default(0)
                                        ->prefix('$')
                                        ->required(),
                                    Forms\Components\TextInput::make('price_adjustment_single_supplement')
                                        ->label('رسوم الإقامة الفردية (Single Supplement)')
                                        ->numeric()
                                        ->default(0)
                                        ->prefix('$')
                                        ->required()
                                        ->helperText('رسوم إضافية عند إشغال الغرفة بشخص واحد فقط'),
                                    Forms\Components\Toggle::make('is_active')
                                        ->label('متاح')
                                        ->default(true),
                                    Forms\Components\TextInput::make('sort_order')
                                        ->label('الترتيب')
                                        ->numeric()
                                        ->default(0),
                                ]),
                            ])
                            ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
                            ->collapsible()
                            ->collapsed()
                            ->columnSpanFull()
                            ->defaultItems(0)
                            ->addActionLabel('إضافة نوع غرفة'),
                    ])
                    ->itemLabel(fn (array $state): ?string => $state['label'] ?? null)
                    ->collapsible()
                    ->collapsed()
                    ->columnSpanFull()
                    ->defaultItems(0)
                    ->addActionLabel('إضافة خيار فندق'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('label')
            ->defaultSort('sequence')
            ->columns([
                Tables\Columns\TextColumn::make('sequence')
                    ->label('الترتيب')
                    ->sortable(),
                Tables\Columns\TextColumn::make('label')
                    ->label('اسم المرحلة')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('start_date')
                    ->label('من تاريخ')
                    ->date(),
                Tables\Columns\TextColumn::make('end_date')
                    ->label('إلى تاريخ')
                    ->date(),
                Tables\Columns\TextColumn::make('hotel_options_count')
                    ->label('خيارات الفنادق')
                    ->counts('hotelOptions')
                    ->badge(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('إضافة مرحلة إقامة')
                    ->mutateFormDataUsing(function (array $data): array {
                        if (auth()->check()) {
                            $data['tenant_id'] = \Filament\Facades\Filament::getTenant()?->id;
                        }
                        return $data;
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
