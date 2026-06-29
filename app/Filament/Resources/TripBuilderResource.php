<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TripBuilderResource\Pages;
use App\Filament\Resources\TripBuilderResource\RelationManagers;
use App\Models\TripBuilder;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class TripBuilderResource extends Resource
{
    protected static ?string $model = \App\Models\TripTemplate::class;

    protected static ?string $navigationIcon = 'heroicon-o-sparkles';
    protected static ?string $navigationGroup = 'Trips';

    public static function getNavigationLabel(): string
    {
        return 'منشئ الرحلات (Wizard)';
    }

    public static function getModelLabel(): string
    {
        return 'رحلة جديدة';
    }

    public static function getPluralModelLabel(): string
    {
        return 'منشئ الرحلات';
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Wizard::make([
                    Forms\Components\Wizard\Step::make('معلومات الرحلة')
                        ->schema([
                            Forms\Components\TextInput::make('title')
                                ->label('الاسم')
                                ->required(),
                            Forms\Components\Select::make('requirement_preset_id')
                                ->label('قالب المتطلبات الجاهز')
                                ->relationship('requirementPreset', 'title')
                                ->searchable()
                                ->preload()
                                ->live()
                                ->afterStateUpdated(function (Forms\Set $set, ?string $state) {
                                    if ($state) {
                                        $preset = \App\Models\RequirementPreset::find($state);
                                        if ($preset) {
                                            $set('passenger_requirements', $preset->items);
                                        }
                                    }
                                }),
                            Forms\Components\Select::make('pickup_routes')
                                ->label('مسارات التجمع (Pickup Routes)')
                                ->multiple()
                                ->options(\App\Models\PickupRoute::pluck('name', 'id'))
                                ->searchable()
                                ->preload()
                                ->required(),
                            Forms\Components\MarkdownEditor::make('description')
                                ->label('الوصف'),
                            // Hidden field for passenger requirements since it's populated by preset
                            Forms\Components\Hidden::make('passenger_requirements'),
                        ]),
                        
                    Forms\Components\Wizard\Step::make('التسعير والإضافات')
                        ->schema([
                            Forms\Components\TextInput::make('base_price')
                                ->label('السعر الأساسي')
                                ->numeric()
                                ->required(),
                            Forms\Components\Toggle::make('deposit_enabled')
                                ->label('تفعيل الدفع الجزئي (عربون)')
                                ->live()
                                ->default(false),
                            Forms\Components\TextInput::make('deposit_percentage')
                                ->label('نسبة العربون (%)')
                                ->numeric()
                                ->minValue(1)
                                ->maxValue(100)
                                ->visible(fn (Forms\Get $get) => $get('deposit_enabled')),
                            Forms\Components\Repeater::make('templatePassengerCategories')
                                ->relationship()
                                ->label('فئات التسعير')
                                ->schema([
                                    Forms\Components\TextInput::make('name')->required()->label('الاسم'),
                                    Forms\Components\TextInput::make('price')->required()->numeric()->label('السعر'),
                                ])->columns(2)->defaultItems(1),
                            Forms\Components\Repeater::make('templateAddons')
                                ->relationship()
                                ->label('الإضافات')
                                ->schema([
                                    Forms\Components\TextInput::make('name')->required()->label('الاسم'),
                                    Forms\Components\TextInput::make('price')->required()->numeric()->label('السعر'),
                                    Forms\Components\TextInput::make('max_quantity')->numeric()->label('العدد الأقصى'),
                                ])->columns(3)->defaultItems(0),
                        ]),

                    Forms\Components\Wizard\Step::make('الجدولة (Schedule)')
                        ->schema([
                            Forms\Components\TextInput::make('seats_count')
                                ->label('عدد المقاعد للرحلة')
                                ->numeric()
                                ->required(),
                            Forms\Components\Radio::make('schedule_type')
                                ->label('نوع الجدولة')
                                ->options([
                                    'single' => 'تاريخ واحد (رحلة مفردة)',
                                    'recurring' => 'متكرر (عدة تواريخ)',
                                ])
                                ->default('single')
                                ->live(),
                            
                            // Single Date
                            Forms\Components\DatePicker::make('single_date')
                                ->label('تاريخ الرحلة')
                                ->required()
                                ->visible(fn (Forms\Get $get) => $get('schedule_type') === 'single'),
                            
                            // Recurring Dates
                            Forms\Components\DatePicker::make('recurring_start')
                                ->label('من تاريخ')
                                ->required(fn (Forms\Get $get) => $get('schedule_type') === 'recurring')
                                ->visible(fn (Forms\Get $get) => $get('schedule_type') === 'recurring'),
                            Forms\Components\DatePicker::make('recurring_end')
                                ->label('إلى تاريخ')
                                ->required(fn (Forms\Get $get) => $get('schedule_type') === 'recurring')
                                ->visible(fn (Forms\Get $get) => $get('schedule_type') === 'recurring'),
                            Forms\Components\CheckboxList::make('recurring_days')
                                ->label('أيام التكرار')
                                ->options([
                                    1 => 'الاثنين',
                                    2 => 'الثلاثاء',
                                    3 => 'الأربعاء',
                                    4 => 'الخميس',
                                    5 => 'الجمعة',
                                    6 => 'السبت',
                                    0 => 'الأحد',
                                ])
                                ->required(fn (Forms\Get $get) => $get('schedule_type') === 'recurring')
                                ->visible(fn (Forms\Get $get) => $get('schedule_type') === 'recurring')
                                ->columns(4),
                        ]),
                        
                    Forms\Components\Wizard\Step::make('نشر المواعيد')
                        ->schema([
                            Forms\Components\Placeholder::make('summary')
                                ->label('ملخص العملية')
                                ->content('اضغط على "Create" أدناه ليتم إنشاء القالب وتوليد المواعيد المجدولة في الخلفية.'),
                            Forms\Components\Toggle::make('publish_immediately')
                                ->label('نشر الرحلات فوراً للعملاء (Active)')
                                ->default(true),
                        ]),
                ])->columnSpanFull()
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                //
            ])
            ->filters([
                //
            ])
            ->actions([
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
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTripBuilders::route('/'),
            'create' => Pages\CreateTripBuilder::route('/create'),
            'edit' => Pages\EditTripBuilder::route('/{record}/edit'),
        ];
    }
}
