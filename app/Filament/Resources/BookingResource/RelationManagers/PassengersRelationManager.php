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
                        ->nullable(),
                    TextInput::make('document_number')
                        ->label('رقم الوثيقة')
                        ->maxLength(255)
                        ->nullable(),
                    DatePicker::make('date_of_birth')
                        ->label('تاريخ الميلاد')
                        ->nullable(),
                    Select::make('gender')
                        ->label('الجنس')
                        ->options(['male' => 'ذكر', 'female' => 'أنثى'])
                        ->nullable(),
                    TextInput::make('seat_number')
                        ->label('رقم المقعد')
                        ->maxLength(255)
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
                TextColumn::make('display_name')
                    ->label('اسم الراكب')
                    ->getStateUsing(fn ($record) => $record->display_name ?? ($record->first_name ? trim($record->first_name . ' ' . $record->last_name) : $record->passenger_label))
                    ->searchable(['first_name', 'last_name', 'passenger_label'])
                    ->sortable(['first_name']),
                
                TextColumn::make('seat_number')
                    ->label('رقم المقعد')
                    ->searchable(),

                IconColumn::make('data_complete')
                    ->label('البيانات')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-exclamation-triangle')
                    ->trueColor('success')
                    ->falseColor('warning')
                    ->tooltip(fn ($state) => $state ? 'بيانات مكتملة' : 'بيانات ناقصة (حجز سريع)'),

                TextColumn::make('document_type')
                    ->label('نوع الوثيقة')
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'national_id' => 'هوية وطنية',
                        'passport'    => 'جواز سفر',
                        default       => $state ?? '—',
                    }),
                TextColumn::make('document_number')
                    ->label('رقم الوثيقة')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('tripPassengerCategory.name')
                    ->label('الفئة')
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
                        $data['data_complete'] = true;
                        return $data;
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->label(fn ($record) => $record->data_complete ? 'تعديل' : 'إكمال البيانات')
                    ->color(fn ($record) => $record->data_complete ? 'primary' : 'warning')
                    ->icon(fn ($record) => $record->data_complete ? 'heroicon-o-pencil' : 'heroicon-o-identification')
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['data_complete'] = true;
                        return $data;
                    }),
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
