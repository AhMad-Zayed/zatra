<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PaymentResource\Pages;
use App\Filament\Resources\PaymentResource\RelationManagers;
use App\Models\Payment;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';

    public static function getNavigationLabel(): string
    {
        return 'المدفوعات';
    }

    public static function getModelLabel(): string
    {
        return 'دفعة مالية';
    }

    public static function getPluralModelLabel(): string
    {
        return 'سجل المدفوعات';
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('booking_id')
                    ->relationship('booking', 'reference')
                    ->label('رقم مرجع الحجز')
                    ->required()
                    ->searchable()
                    ->preload(),
                Forms\Components\TextInput::make('amount')
                    ->label('المبلغ')
                    ->numeric()
                    ->required()
                    ->prefix('$'),
                Forms\Components\Select::make('payment_method')
                    ->label('طريقة الدفع')
                    ->options([
                        'cash' => 'نقدي',
                        'transfer' => 'تحويل بنكي',
                        'visa' => 'بطاقة ائتمان / فيزا',
                    ])
                    ->required(),
                Forms\Components\Select::make('received_by')
                    ->relationship('receivedBy', 'name')
                    ->label('استُلمت بواسطة')
                    ->required()
                    ->default(fn() => auth()->id())
                    ->searchable()
                    ->preload(),
                Forms\Components\Select::make('type')
                    ->label('نوع الدفعة')
                    ->options([
                        \App\Enums\PaymentType::DEPOSIT->value => 'دفعة أولى',
                        \App\Enums\PaymentType::INSTALLMENT->value => 'قسط شهري',
                        \App\Enums\PaymentType::FULL->value => 'كامل القيمة',
                        \App\Enums\PaymentType::REVERSAL->value => 'عكس قيد / تراجع',
                        \App\Enums\PaymentType::PAYMENT->value => 'دفعة عامة',
                        \App\Enums\PaymentType::REFUND->value => 'مسترد مالي',
                    ])
                    ->required()
                    ->default(\App\Enums\PaymentType::DEPOSIT),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('booking.reference')
                    ->label('رقم الحجز')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('amount')
                    ->label('المبلغ')
                    ->money('USD')
                    ->sortable(),
                Tables\Columns\TextColumn::make('payment_method')
                    ->label('طريقة الدفع')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'cash' => 'نقدي',
                        'transfer' => 'تحويل بنكي',
                        'visa' => 'فيزا / بطاقة ائتمان',
                        default => $state,
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('receivedBy.name')
                    ->label('الموظف المستلم')
                    ->sortable(),
                Tables\Columns\TextColumn::make('type')
                    ->label('النوع')
                    ->badge()
                    ->colors([
                        'warning' => \App\Enums\PaymentType::DEPOSIT,
                        'info' => \App\Enums\PaymentType::INSTALLMENT,
                        'success' => \App\Enums\PaymentType::FULL,
                        'danger' => \App\Enums\PaymentType::REVERSAL,
                        'primary' => \App\Enums\PaymentType::PAYMENT,
                        'secondary' => \App\Enums\PaymentType::REFUND,
                    ])
                    ->formatStateUsing(fn (\App\Enums\PaymentType $state): string => match ($state) {
                        \App\Enums\PaymentType::DEPOSIT => 'دفعة أولى',
                        \App\Enums\PaymentType::INSTALLMENT => 'قسط شهري',
                        \App\Enums\PaymentType::FULL => 'كامل القيمة',
                        \App\Enums\PaymentType::REVERSAL => 'عكس قيد / تراجع',
                        \App\Enums\PaymentType::PAYMENT => 'دفعة عامة',
                        \App\Enums\PaymentType::REFUND => 'مسترد مالي',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاريخ العملية')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([
                // Payments cannot be bulk deleted
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
            'index' => Pages\ListPayments::route('/'),
            'create' => Pages\CreatePayment::route('/create'),
        ];
    }
}
