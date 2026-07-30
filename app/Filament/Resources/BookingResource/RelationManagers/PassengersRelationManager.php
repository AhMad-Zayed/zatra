<?php

namespace App\Filament\Resources\BookingResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PassengersRelationManager extends RelationManager
{
    protected static string $relationship = 'passengers';

    public static function getTitle(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): string
    {
        return 'بيانات المسافرين ومستنداتهم';
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Grid::make(2)->schema([
                    TextInput::make('first_name')
                        ->label('الاسم الأول')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('last_name')
                        ->label('اسم العائلة')
                        ->required()
                        ->maxLength(255),
                    Select::make('document_type')
                        ->label('نوع الوثيقة')
                        ->options([
                            'national_id' => 'هوية وطنية',
                            'passport'    => 'جواز سفر',
                        ])
                        ->required(),
                    TextInput::make('document_number')
                        ->label('رقم الوثيقة')
                        ->required()
                        ->maxLength(255),
                    DatePicker::make('date_of_birth')
                        ->label('تاريخ الميلاد')
                        ->nullable(),
                    Select::make('gender')
                        ->label('الجنس')
                        ->options(['male' => 'ذكر', 'female' => 'أنثى'])
                        ->nullable(),
                    Select::make('trip_passenger_category_id')
                        ->label('فئة المسافر (الباقة)')
                        ->options(fn ($livewire) =>
                            $livewire->ownerRecord
                                ?->tripInstance
                                ?->tripPassengerCategories
                                ?->pluck('name', 'id') ?? []
                        )
                        ->required(),
                    Select::make('pickup_point_id')
                        ->label('نقطة التجمع')
                        ->options(fn ($livewire) =>
                            \App\Models\PickupPoint::whereHas('pickupRoute.tripInstances', fn ($q) =>
                                $q->where('trip_instances.id', $livewire->ownerRecord?->trip_instance_id)
                            )->pluck('name', 'id')
                        )
                        ->nullable(),
                ]),

                Forms\Components\Section::make('وثائق المسافر')
                    ->schema([
                        Forms\Components\SpatieMediaLibraryFileUpload::make('passport')
                            ->label('تحميل صورة الجواز')
                            ->collection('identity_documents')
                            ->maxSize(5120),
                        Forms\Components\SpatieMediaLibraryFileUpload::make('national_id')
                            ->label('تحميل صورة الهوية')
                            ->collection('identity_documents')
                            ->maxSize(5120),
                    ])
                    ->columns(2),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('first_name')
            ->columns([
                TextColumn::make('first_name')
                    ->label('الاسم الأول')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('last_name')
                    ->label('اسم العائلة')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('document_type')
                    ->label('نوع الوثيقة')
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'national_id' => 'هوية وطنية',
                        'passport'    => 'جواز سفر',
                        default       => $state,
                    }),
                TextColumn::make('document_number')
                    ->label('رقم الوثيقة')
                    ->searchable(),
                TextColumn::make('tripPassengerCategory.name')
                    ->label('الفئة')
                    ->placeholder('—'),
                TextColumn::make('pickupPoint.name')
                    ->label('نقطة التجمع')
                    ->placeholder('—'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('إضافة مسافر جديد')
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['tenant_id'] = $this->getOwnerRecord()->tenant_id;
                        return $data;
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('تعديل'),
                Tables\Actions\DeleteAction::make()->label('حذف'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    \pxlrbt\FilamentExcel\Actions\Tables\ExportBulkAction::make()
                        ->label('تصدير كشف المسافرين إلى Excel'),
                ]),
            ]);
    }
}
