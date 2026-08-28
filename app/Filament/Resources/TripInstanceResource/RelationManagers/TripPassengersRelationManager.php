<?php
namespace App\Filament\Resources\TripInstanceResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TripPassengersRelationManager extends RelationManager
{
    protected static string $relationship = 'passengers';
    protected static ?string $title = 'جميع الركاب';
    protected static ?string $icon = 'heroicon-o-users';

    public static function getModelLabel(): string
    {
        return 'راكب';
    }

    public static function getPluralModelLabel(): string
    {
        return 'الركاب';
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('first_name')
                    ->label('الاسم الأول')
                    ->searchable(),
                Tables\Columns\TextColumn::make('last_name')
                    ->label('اسم العائلة')
                    ->searchable(),
                Tables\Columns\TextColumn::make('booking.pnr')
                    ->label('رقم الحجز')
                    ->badge()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('booking.customer.name')
                    ->label('الحاجز'),
                Tables\Columns\TextColumn::make('tripPassengerCategory.name')
                    ->label('الفئة')
                    ->badge(),
                Tables\Columns\TextColumn::make('document_number')
                    ->label('رقم الهوية')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('data_complete')
                    ->label('البيانات مكتملة')
                    ->boolean(),
                Tables\Columns\IconColumn::make('is_checked_in')
                    ->label('تسجيل الوصول')
                    ->boolean(),
            ])
            ->defaultSort('booking_id', 'asc');
    }
}
