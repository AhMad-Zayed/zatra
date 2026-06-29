<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TripTemplateResource\Pages;
use App\Filament\Resources\TripTemplateResource\RelationManagers;
use App\Models\TripTemplate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class TripTemplateResource extends Resource
{
    protected static ?string $model = TripTemplate::class;
    protected static ?string $navigationIcon = 'heroicon-o-document-duplicate';

    protected static bool $shouldRegisterNavigation = false;

    public static function getNavigationLabel(): string
    {
        return 'قوالب الرحلات';
    }

    public static function getModelLabel(): string
    {
        return 'قالب رحلة';
    }

    public static function getPluralModelLabel(): string
    {
        return 'قوالب الرحلات';
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('المعلومات الأساسية')
                    ->schema([
                        Forms\Components\TextInput::make('title')
                            ->label('اسم القالب / الرحلة')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('base_price')
                            ->label('السعر الأساسي (الافتراضي)')
                            ->numeric()
                            ->required()
                            ->prefix('$')
                            ->helperText('هذا السعر يظهر للواجهة، يمكن تغييره لكل موعد رحلة.'),
                            
                        Forms\Components\Toggle::make('deposit_enabled')
                            ->label('تفعيل الدفع الجزئي (عربون)')
                            ->live()
                            ->default(false),
                            
                        Forms\Components\TextInput::make('deposit_percentage')
                            ->label('نسبة العربون (%)')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(100)
                            ->suffix('%')
                            ->visible(fn (Forms\Get $get) => $get('deposit_enabled')),
                            
                        Forms\Components\MarkdownEditor::make('description')
                            ->label('الوصف والتفاصيل')
                            ->columnSpanFull(),
                    ])->columns(2),

                Forms\Components\Section::make('فئات التسعير (Pricing Tiers)')
                    ->description('تحديد أسعار مختلفة بناءً على الفئة (بالغ، طفل، إلخ). سيتم نسخها لأي موعد جديد.')
                    ->schema([
                        Forms\Components\Repeater::make('templatePassengerCategories')
                            ->relationship()
                            ->label('الفئات')
                            ->schema([
                                Forms\Components\Select::make('global_pricing_tier_id')
                                    ->label('استيراد من المكتبة')
                                    ->relationship('passengerCategory', 'name')
                                    ->searchable()
                                    ->preload()
                                    ->createOptionForm([
                                        Forms\Components\TextInput::make('name')
                                            ->label('الاسم')
                                            ->required()
                                            ->maxLength(255),
                                        Forms\Components\TextInput::make('default_price')
                                            ->label('السعر الافتراضي')
                                            ->numeric()
                                            ->required()
                                            ->prefix('$'),
                                    ])
                                    ->live()
                                    ->afterStateUpdated(function (Forms\Set $set, ?string $state) {
                                        if ($state) {
                                            $global = \App\Models\PassengerCategory::find($state);
                                            if ($global) {
                                                $set('name', $global->name);
                                                $set('price', $global->default_price);
                                            }
                                        }
                                    })
                                    ->columnSpan(2),
                                Forms\Components\TextInput::make('name')
                                    ->label('اسم الفئة (يُحفظ كلقطة)')
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('price')
                                    ->label('السعر (يُحفظ كلقطة)')
                                    ->numeric()
                                    ->required()
                                    ->prefix('$'),
                            ])
                            ->columns(2)
                            ->defaultItems(1)
                            ->addActionLabel('إضافة فئة تسعير'),
                    ]),

                Forms\Components\Section::make('الإضافات الاختيارية (Add-ons)')
                    ->description('خدمات إضافية يمكن للعميل اختيارها (مثل: غرفة مفردة، مواصلات).')
                    ->schema([
                        Forms\Components\Repeater::make('templateAddons')
                            ->relationship()
                            ->label('الإضافات')
                            ->schema([
                                Forms\Components\Select::make('global_addon_id')
                                    ->label('استيراد من المكتبة')
                                    ->relationship('globalAddon', 'name')
                                    ->searchable()
                                    ->preload()
                                    ->createOptionForm([
                                        Forms\Components\TextInput::make('name')
                                            ->label('الاسم')
                                            ->required()
                                            ->maxLength(255),
                                        Forms\Components\TextInput::make('default_price')
                                            ->label('السعر الافتراضي')
                                            ->numeric()
                                            ->required()
                                            ->prefix('$'),
                                    ])
                                    ->live()
                                    ->afterStateUpdated(function (Forms\Set $set, ?string $state) {
                                        if ($state) {
                                            $global = \App\Models\GlobalAddon::find($state);
                                            if ($global) {
                                                $set('name', $global->name);
                                                $set('price', $global->default_price);
                                            }
                                        }
                                    })
                                    ->columnSpan(3),
                                Forms\Components\TextInput::make('name')
                                    ->label('اسم الإضافة (يُحفظ كلقطة)')
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('price')
                                    ->label('السعر الإضافي (يُحفظ كلقطة)')
                                    ->numeric()
                                    ->required()
                                    ->prefix('$'),
                                Forms\Components\TextInput::make('max_quantity')
                                    ->label('العدد الأقصى المتاح')
                                    ->numeric()
                                    ->helperText('اتركه فارغاً لعدد غير محدود'),
                            ])
                            ->columns(3)
                            ->defaultItems(0)
                            ->addActionLabel('إضافة خدمة'),
                    ]),

                Forms\Components\Section::make('متطلبات المسافرين (Dynamic Form)')
                    ->description('بناء النموذج الذي سيراه العميل لكل مسافر (مثل طلب صورة الجواز، رقم الهوية). يمكنك اختيار قالب جاهز بدلاً من الإدخال اليدوي.')
                    ->schema([
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

                        Forms\Components\Repeater::make('passenger_requirements')
                            ->label('الحقول المطلوبة')
                            ->schema([
                                Forms\Components\TextInput::make('name')
                                    ->label('اسم الحقل (مثال: صورة الجواز)')
                                    ->required(),
                                Forms\Components\Select::make('type')
                                    ->label('نوع الحقل')
                                    ->options([
                                        'text' => 'نص قصير',
                                        'image' => 'صورة / ملف',
                                        'date' => 'تاريخ',
                                    ])
                                    ->required(),
                                Forms\Components\Toggle::make('is_required')
                                    ->label('إجباري؟')
                                    ->default(true),
                            ])
                            ->columns(3)
                            ->defaultItems(0)
                            ->addActionLabel('إضافة متطلب'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label('اسم القالب')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('base_price')
                    ->label('السعر الأساسي')
                    ->money('USD')
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
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
            RelationManagers\TripInstancesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTripTemplates::route('/'),
            'create' => Pages\CreateTripTemplate::route('/create'),
            'edit' => Pages\EditTripTemplate::route('/{record}/edit'),
        ];
    }
}
