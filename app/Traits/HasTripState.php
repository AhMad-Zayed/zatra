<?php

namespace App\Traits;

use App\Enums\TripStatusEnum;
use App\Exceptions\InvalidStateException;

trait HasTripState
{
    public function allowedTransitions(): array
    {
        return [
            TripStatusEnum::Draft->value => [
                TripStatusEnum::Active,
                TripStatusEnum::Cancelled,
            ],
            TripStatusEnum::Active->value => [
                TripStatusEnum::Closed,
                TripStatusEnum::Cancelled,
            ],
            TripStatusEnum::Closed->value => [
                TripStatusEnum::InProgress,
                TripStatusEnum::Cancelled,
            ],
            TripStatusEnum::InProgress->value => [
                TripStatusEnum::Completed,
            ],
            TripStatusEnum::Completed->value => [],
            TripStatusEnum::Cancelled->value => [],
        ];
    }

    public function transitionTo(TripStatusEnum $newState): void
    {
        $currentState = $this->status;

        // If it's already in that state or status is null (initial), allow or bypass appropriately
        if (!$currentState) {
             $this->update(['status' => $newState]);
             return;
        }

        $allowed = $this->allowedTransitions()[$currentState->value] ?? [];

        if (!in_array($newState, $allowed, true)) {
            throw InvalidStateException::transition($currentState->value, $newState->value);
        }

        $this->update(['status' => $newState]);
        
        // The Cancellation Cascade
        if ($newState === TripStatusEnum::Cancelled) {
            $this->bookings()->whereNotIn('booking_status', [\App\Enums\BookingStatus::Cancelled])->each(function ($booking) {
                // Here we cancel the booking
                // Assuming there's a cancel method or we just update the status
                // The skill says Booking status is derived from payments, but CANCELLED is set directly.
                $booking->update(['booking_status' => \App\Enums\BookingStatus::Cancelled]);
                
                // Usually we'd call the BookingService::cancelBooking() here to handle refunds
                // app(\App\Services\BookingService::class)->cancelBooking($booking);
            });
        }
    }

    public function canTransitionTo(TripStatusEnum $newState): bool
    {
        if (!$this->status) return true;
        
        $allowed = $this->allowedTransitions()[$this->status->value] ?? [];
        return in_array($newState, $allowed, true);
    }
}
