<?php

namespace App\Filament\Resources\TripInstanceResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class BookingsRelationManager extends RelationManager
{
    protected static string $relationship = 'bookings';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('pnr')
                    ->required()
                    ->maxLength(255),
            ]);
    }

    protected static ?string $title = 'الحجوزات';
    protected static ?string $icon = 'heroicon-o-ticket';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('pnr')
            ->columns([
                Tables\Columns\TextColumn::make('pnr')
                    ->label('رقم الحجز')
                    ->weight('bold')
                    ->copyable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('customer.name')
                    ->label('العميل')
                    ->searchable(),
                Tables\Columns\TextColumn::make('booking_status')
                    ->label('حالة الحجز')
                    ->badge(),
                Tables\Columns\TextColumn::make('payment_status')
                    ->label('حالة الدفع')
                    ->badge(),
                Tables\Columns\TextColumn::make('passengers_count')
                    ->label('الركاب')
                    ->counts('passengers')
                    ->badge(),
                Tables\Columns\TextColumn::make('currency')
                    ->label('العملة')
                    ->badge(),
                Tables\Columns\TextColumn::make('balance_due')
                    ->label('المتبقي')
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                // Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\Action::make('view')
                    ->label('عرض')
                    ->url(fn (\App\Models\Booking $record): string => \App\Filament\Resources\BookingResource::getUrl('view', ['record' => $record]))
                    ->icon('heroicon-m-eye'),
            ])
            ->bulkActions([
                // Tables\Actions\BulkActionGroup::make([
                //     Tables\Actions\DeleteBulkAction::make(),
                // ]),
            ]);
    }
}
