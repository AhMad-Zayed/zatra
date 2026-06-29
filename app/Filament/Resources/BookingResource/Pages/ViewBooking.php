<?php

namespace App\Filament\Resources\BookingResource\Pages;

use App\Filament\Resources\BookingResource;
use Filament\Actions;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use App\Enums\BookingStatus;
use Illuminate\Support\Facades\DB;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewBooking extends ViewRecord
{
    protected static string $resource = BookingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
            
            Actions\Action::make('record_payment')
                ->label('تسجيل دفعة (Record Payment)')
                ->icon('heroicon-o-currency-dollar')
                ->color('success')
                ->visible(fn ($record) => $record->balance_due > 0 && $record->booking_status !== BookingStatus::Cancelled)
                ->form([
                    TextInput::make('amount')
                        ->label('المبلغ (Amount)')
                        ->required()
                        ->numeric()
                        ->minValue(0.01)
                        ->maxValue(fn ($record) => $record->balance_due)
                        ->prefix('SAR'),
                        
                    Select::make('payment_method')
                        ->label('طريقة الدفع (Payment Method)')
                        ->required()
                        ->options([
                            'cash' => 'نقدي (Cash)',
                            'bank_transfer' => 'حوالة بنكية (Bank Transfer)',
                            'e_wallet' => 'محفظة إلكترونية (E-Wallet)',
                        ]),
                        
                    Textarea::make('reference_note')
                        ->label('ملاحظات/رقم المرجع (Reference/Note)')
                        ->nullable(),
                ])
                ->action(function (array $data, $record) {
                    DB::transaction(function () use ($data, $record) {
                        // Create Payment
                        $record->payments()->create([
                            'tenant_id' => $record->tenant_id,
                            'amount' => $data['amount'],
                            'payment_method' => $data['payment_method'],
                            'reference_note' => $data['reference_note'],
                        ]);

                        // Update Ledger
                        $newTotalPaid = $record->total_paid + $data['amount'];
                        $newBalanceDue = $record->balance_due - $data['amount'];

                        $record->update([
                            'total_paid' => $newTotalPaid,
                            'balance_due' => $newBalanceDue,
                        ]);

                        // Status Transition & Ticketing
                        if ($record->balance_due <= 0) {
                            $record->update(['booking_status' => BookingStatus::Confirmed]);
                            
                            // Trigger Ticket
                            if (class_exists(\App\Services\TicketGenerationService::class)) {
                                $ticketService = app(\App\Services\TicketGenerationService::class);
                                $ticketPath = $ticketService->generateAndStoreTicket($record);
                                
                                // Dispatch Job (Assuming it exists or will be generated)
                                if (class_exists(\App\Jobs\SendBookingNotificationJob::class)) {
                                    \App\Jobs\SendBookingNotificationJob::dispatch($record, $ticketPath);
                                }
                            }
                            
                            Notification::make()->title('تم تسجيل الدفعة واكتمال الحجز')->success()->send();
                        } else {
                            Notification::make()->title('تم تسجيل الدفعة بنجاح')->success()->send();
                        }
                    });
                }),
        ];
    }
}
