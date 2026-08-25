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

    protected static ?string $navigationGroup = 'المالية';
    protected static ?int $navigationSort = 1;

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

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('tenant_id', \Filament\Facades\Filament::getTenant()->id);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('booking_id')
                    ->relationship('booking', 'pnr')
                    ->label('رقم مرجع الحجز')
                    ->required()
                    ->searchable()
                    ->preload()
                    ->live()
                    ->afterStateUpdated(function (Forms\Set $set, ?string $state) {
                        if ($state) {
                            $booking = \App\Models\Booking::find($state);
                            if ($booking) {
                                $set('currency', $booking->currency);
                            }
                        }
                    }),
                Forms\Components\Select::make('currency')
                    ->label('العملة')
                    ->options([
                        'USD' => 'دولار (USD)',
                        'ILS' => 'شيكل (ILS)',
                    ])
                    ->required()
                    ->disabled()
                    ->dehydrated(),
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
                        // 'visa' => 'بطاقة ائتمان / فيزا', // Disabled electronic payment
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
                Tables\Columns\TextColumn::make('booking.pnr')
                    ->label('رقم الحجز')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('amount')
                    ->label('المبلغ')
                    ->sortable(),
                Tables\Columns\TextColumn::make('currency')
                    ->label('العملة')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'USD' => 'success',
                        'ILS' => 'info',
                        default => 'gray',
                    })
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
                Tables\Filters\SelectFilter::make('payment_method')
                    ->label('طريقة الدفع')
                    ->options([
                        'cash' => 'نقدي',
                        'transfer' => 'تحويل بنكي',
                        'visa' => 'بطاقة ائتمان / فيزا',
                    ]),
                Tables\Filters\Filter::make('created_at')
                    ->label('تاريخ العملية')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('من تاريخ'),
                        Forms\Components\DatePicker::make('until')->label('إلى تاريخ'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'], fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'], fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date))
                    ),
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
