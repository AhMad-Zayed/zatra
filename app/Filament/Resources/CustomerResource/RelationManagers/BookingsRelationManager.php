<?php

namespace App\Filament\Resources\CustomerResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use App\Models\Booking;

class BookingsRelationManager extends RelationManager
{
    protected static string $relationship = 'bookings';

    public static function getTitle(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): string
    {
        return 'حجوزات العميل';
    }

    public static function getModelLabel(): string
    {
        return 'حجز';
    }

    public static function getPluralModelLabel(): string
    {
        return 'الحجوزات';
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            // Bookings are created via BookingResource, not from CustomerResource
            // This manager is read-only via ViewAction
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('pnr')
            ->columns([
                TextColumn::make('pnr')
                    ->label('رقم الحجز (PNR)')
                    ->searchable()
                    ->copyable()
                    ->weight('bold')
                    ->color('primary'),
                TextColumn::make('tripInstance.tripTemplate.title')
                    ->label('الرحلة'),
                TextColumn::make('tripInstance.start_date')
                    ->label('تاريخ الرحلة')
                    ->date('d/m/Y'),
                TextColumn::make('booking_status')
                    ->label('حالة الحجز')
                    ->badge(),
                TextColumn::make('payment_status')
                    ->label('حالة الدفع')
                    ->badge(),
                // grand_total stored as cents, MoneyCast handles /100 when used with ->money()
                TextColumn::make('grand_total')
                    ->label('الإجمالي')
                    ->money('SAR'),
                // balance_due: real DB column (cents), MoneyCast applies on model access
                TextColumn::make('balance_due')
                    ->label('المتبقي')
                    ->money('SAR')
                    ->color(fn ($state) => $state <= 0 ? 'success' : 'danger'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                // Bookings are created via BookingResource wizard, not here
            ])
            ->actions([
                Tables\Actions\Action::make('view_booking')
                    ->label('فتح الحجز')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Booking $record): string =>
                        route('filament.admin.resources.bookings.view', [
                            'tenant' => $record->tenant_id,
                            'record' => $record->id,
                        ])
                    )
                    ->openUrlInNewTab(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
