<?php

namespace App\Filament\Resources\BookingResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

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
                Forms\Components\TextInput::make('name')
                    ->label('اسم المسافر الكامل')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('passport_number')
                    ->label('رقم جواز السفر')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Textarea::make('special_requirements')
                    ->label('متطلبات خاصة بالمسافر')
                    ->rows(2)
                    ->nullable()
                    ->columnSpanFull(),
                Forms\Components\Section::make('وثائق المسافر')
                    ->schema([
                        Forms\Components\SpatieMediaLibraryFileUpload::make('passport')
                            ->label('تحميل صورة الجواز')
                            ->collection('passport')
                            ->maxSize(5120),
                        Forms\Components\SpatieMediaLibraryFileUpload::make('national_id')
                            ->label('تحميل صورة الهوية')
                            ->collection('national_id')
                            ->maxSize(5120),
                    ])
                    ->columns(2),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('اسم المسافر')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('passport_number')
                    ->label('رقم الجواز')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('special_requirements')
                    ->label('المتطلبات الخاصة')
                    ->limit(50),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('إضافة مسافر جديد للرحلة')
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['tenant_id'] = $this->getOwnerRecord()->tenant_id;
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
                    \pxlrbt\FilamentExcel\Actions\Tables\ExportBulkAction::make()
                        ->label('تصدير كشف المسافرين إلى Excel'),
                ]),
            ]);
    }
}
