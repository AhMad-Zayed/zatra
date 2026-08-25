<?php

namespace App\Filament\Resources\PaymentResource\Pages;

use App\Filament\Resources\PaymentResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreatePayment extends CreateRecord
{
    protected static string $resource = PaymentResource::class;

    /**
     * P0-6: creation and recalculation now delegate to the canonical
     * BookingService::recordPayment() instead of this page independently reimplementing
     * both. Uses the form's `booking_id` to resolve the Booking, mirroring what the removed
     * manual logic here previously assumed.
     */
    protected function handleRecordCreation(array $data): \Illuminate\Database\Eloquent\Model
    {
        $booking = \App\Models\Booking::findOrFail($data['booking_id']);

        $type = $data['type'] instanceof \App\Enums\PaymentType
            ? $data['type']
            : \App\Enums\PaymentType::from($data['type'] ?? \App\Enums\PaymentType::DEPOSIT->value);

        return app(\App\Services\BookingService::class)->recordPayment(
            $booking,
            (float) $data['amount'],
            $data['payment_method'],
            auth()->user(),
            $type,
        );
    }
}
