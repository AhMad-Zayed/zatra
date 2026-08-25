<?php

namespace App\Filament\Resources\BookingResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

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
                    ->minValue(1)
                    ->helperText('الحد الأدنى للدفعة: 1')
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
                // P0-6: business logic (payment creation, recalculation, status transition)
                // now lives entirely in BookingService::recordPayment() — this action is a
                // thin orchestration layer supplying form data and a success notification.
                Tables\Actions\CreateAction::make()
                    ->using(function (array $data) {
                        return app(\App\Services\BookingService::class)->recordPayment(
                            $this->getOwnerRecord(),
                            (float) $data['amount'],
                            $data['payment_method'],
                            auth()->user(),
                            \App\Enums\PaymentType::PAYMENT,
                            $data['reference_number'] ?? null,
                            $data['currency'] ?? null,
                        );
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
                        try {
                            app(\App\Services\BookingService::class)->reversePayment(
                                $record,
                                'Manual reversal via admin panel',
                                auth()->user(),
                            );
                        } catch (\RuntimeException $e) {
                            \Filament\Notifications\Notification::make()
                                ->title('تعذر عكس الدفعة')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                            return;
                        }

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
