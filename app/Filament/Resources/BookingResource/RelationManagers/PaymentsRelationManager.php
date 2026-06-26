<?php

namespace App\Filament\Resources\BookingResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

use App\Enums\PaymentStatus;

class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';
    
    protected static ?string $title = 'الدفعات المالية';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('amount')
                    ->label('المبلغ')
                    ->required()
                    ->numeric()
                    ->prefix('$')
                    ->maxValue(function () {
                        return $this->getOwnerRecord()->balance_due;
                    })
                    ->rule(function () {
                        return function (string $attribute, $value, \Closure $fail) {
                            if ($value > $this->getOwnerRecord()->balance_due) {
                                $fail('المبلغ لا يمكن أن يتجاوز المبلغ المتبقي.');
                            }
                        };
                    }),
                Forms\Components\Select::make('payment_method')
                    ->label('طريقة الدفع')
                    ->options([
                        'cash' => 'نقدي',
                        'bank_transfer' => 'تحويل بنكي',
                        'credit_card' => 'بطاقة ائتمان',
                    ])
                    ->required(),
                Forms\Components\TextInput::make('reference_number')
                    ->label('الرقم المرجعي')
                    ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('amount')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('التاريخ')
                    ->dateTime(),
                Tables\Columns\TextColumn::make('amount')
                    ->label('المبلغ')
                    ->money('USD'),
                Tables\Columns\TextColumn::make('payment_method')
                    ->label('طريقة الدفع'),
                Tables\Columns\TextColumn::make('reference_number')
                    ->label('الرقم المرجعي'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->after(function () {
                        $booking = $this->getOwnerRecord();
                        $totalPaid = $booking->payments()->sum('amount');
                        $balanceDue = $booking->grand_total - $totalPaid;
                        
                        $paymentStatus = PaymentStatus::Unpaid;
                        if ($totalPaid > 0 && $balanceDue > 0) {
                            $paymentStatus = PaymentStatus::PartiallyPaid;
                        } elseif ($balanceDue <= 0) {
                            $paymentStatus = PaymentStatus::Paid;
                        }
                        
                        $booking->update([
                            'total_paid' => $totalPaid,
                            'balance_due' => $balanceDue,
                            'payment_status' => $paymentStatus,
                        ]);
                    })
                    ->visible(fn () => $this->getOwnerRecord()->balance_due > 0),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([
            ]);
    }
}
