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
                    ->default(fn () => $this->getOwnerRecord()?->balance_due)
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
                Forms\Components\Select::make('currency')
                    ->label('العملة')
                    ->options([
                        'USD' => 'دولار (USD)',
                        'ILS' => 'شيكل (ILS)',
                    ])
                    ->default(fn () => $this->getOwnerRecord()?->currency ?? 'USD')
                    ->disabled() // Must match the booking exactly
                    ->dehydrated() // Ensure it is saved
                    ->required(),
                Forms\Components\Select::make('payment_method')
                    ->label('طريقة الدفع')
                    ->options([
                        'cash' => 'نقدي',
                        'bank_transfer' => 'تحويل بنكي',
                        // 'credit_card' => 'بطاقة ائتمان', // Disabled electronic payments as requested
                    ])
                    ->default('cash')
                    ->required(),
                Forms\Components\TextInput::make('reference_number')
                    ->label('الرقم المرجعي')
                    ->default(fn () => $this->getOwnerRecord()?->pnr)
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
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('amount')
                    ->label('المبلغ')
                    ->money('USD')
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('type')
                    ->label('نوع الحركة')
                    ->formatStateUsing(fn ($state) => match ($state?->value ?? $state) {
                        'payment' => 'دفعة',
                        'refund' => 'استرداد',
                        'reversal' => 'عكس',
                        default => $state?->value ?? $state,
                    })
                    ->badge()
                    ->color(fn ($state) => match ($state?->value ?? $state) {
                        'payment' => 'success',
                        'refund' => 'warning',
                        'reversal' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('payment_method')
                    ->label('طريقة الدفع')
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'cash' => 'نقدي',
                        'bank_transfer' => 'تحويل بنكي',
                        'credit_card' => 'بطاقة ائتمان',
                        default => $state,
                    })
                    ->badge()
                    ->color('info'),
                Tables\Columns\TextColumn::make('reference_number')
                    ->label('الرقم المرجعي')
                    ->searchable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('receivedBy.name')
                    ->label('استلمها')
                    ->placeholder('النظام'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['tenant_id'] = $this->getOwnerRecord()->tenant_id;
                        $data['type'] = \App\Enums\PaymentType::PAYMENT;
                        $data['received_by'] = auth()->id();
                        return $data;
                    })
                    ->after(function () {
                        $booking = $this->getOwnerRecord();
                        // sum('amount') includes negative amounts from reversals, giving the correct net total.
                        $totalPaidCents = $booking->payments()->sum('amount');
                        
                        $totalPaidFloat = $totalPaidCents / 100;
                        $balanceDueFloat = max(0, $booking->grand_total - $totalPaidFloat);
                        
                        $paymentStatus = PaymentStatus::Unpaid;
                        if ($totalPaidFloat > 0 && $balanceDueFloat > 0) {
                            $paymentStatus = PaymentStatus::PartiallyPaid;
                        } elseif ($balanceDueFloat <= 0) {
                            $paymentStatus = PaymentStatus::Paid;
                        }
                        
                        $booking->update([
                            'total_paid' => $totalPaidFloat,
                            'balance_due' => $balanceDueFloat,
                            'payment_status' => $paymentStatus,
                        ]);
                    })
                    ->visible(fn () => $this->getOwnerRecord()->balance_due > 0),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('reverse_payment')
                    ->label('إلغاء وعكس الدفعة')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('عكس الدفعة المالية (تصحيح الأخطاء)')
                    ->modalDescription('بموجب المعايير المحاسبية، يمنع حذف الدفعات. هل أنت متأكد من رغبتك في إلغاء هذه الدفعة؟ سيقوم النظام تلقائياً بإنشاء حركة سالبة (استرداد/عكس) للحفاظ على سلامة السجلات المالية.')
                    ->modalSubmitActionLabel('نعم، قم بعكس الدفعة')
                    ->visible(fn ($record) => $record->type === \App\Enums\PaymentType::PAYMENT && $record->amount > 0)
                    ->action(function ($record) {
                        \App\Models\Payment::create([
                            'tenant_id' => $record->tenant_id,
                            'booking_id' => $record->booking_id,
                            // $record->amount uses MoneyCast, so it is in dollars. Payment accepts dollars and saves cents.
                            'amount' => -$record->amount,
                            'payment_method' => $record->payment_method,
                            'reference_number' => 'REV-' . ($record->reference_number ?: $record->id),
                            'type' => \App\Enums\PaymentType::REVERSAL,
                            'received_by' => auth()->id(),
                        ]);

                        // Recalculate totals
                        $booking = $record->booking;
                        $totalPaidCents = $booking->payments()->sum('amount');
                        
                        $totalPaidFloat = $totalPaidCents / 100;
                        $balanceDueFloat = max(0, $booking->grand_total - $totalPaidFloat);
                        
                        $paymentStatus = PaymentStatus::Unpaid;
                        if ($totalPaidFloat > 0 && $balanceDueFloat > 0) {
                            $paymentStatus = PaymentStatus::PartiallyPaid;
                        } elseif ($balanceDueFloat <= 0) {
                            $paymentStatus = PaymentStatus::Paid;
                        }
                        
                        $booking->update([
                            'total_paid' => $totalPaidFloat,
                            'balance_due' => $balanceDueFloat,
                            'payment_status' => $paymentStatus,
                        ]);
                        
                        \Filament\Notifications\Notification::make()
                            ->title('تم التراجع عن الدفعة بنجاح')
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([
            ]);
    }
}
