<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\User;
use App\Enums\PaymentType;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    /**
     * Record a new payment entry
     */
    public function recordPayment(Booking $booking, float $amount, string $method, User $receivedBy, PaymentType $type = PaymentType::DEPOSIT): Payment
    {
        if (!app()->environment('testing')) {
            $key = 'record-payment:' . $booking->id;
            if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($key, 3)) {
                $seconds = \Illuminate\Support\Facades\RateLimiter::availableIn($key);
                throw new \RuntimeException("لقد تجاوزت الحد الأقصى لتسجيل الدفعات. يرجى الانتظار {$seconds} ثانية.");
            }
            \Illuminate\Support\Facades\RateLimiter::hit($key, 60);
        }

        return DB::transaction(function () use ($booking, $amount, $method, $receivedBy, $type) {
            $payment = Payment::create([
                'tenant_id' => $booking->tenant_id,
                'booking_id' => $booking->id,
                'amount' => $amount,
                'payment_method' => $method,
                'received_by' => $receivedBy->id,
                'type' => $type,
            ]);

            activity()
                ->performedOn($booking)
                ->causedBy(auth()->user() ?? $receivedBy)
                ->withProperties([
                    'payment_id' => $payment->id,
                    'amount' => $payment->amount,
                    'type' => $payment->type?->value,
                    'new_balance' => $booking->fresh()->paid_amount,
                ])
                ->log('payment_recorded');

            return $payment;
        });
    }

    /**
     * Reverse a payment with a negative entry
     */
    public function reversePayment(Payment $original, string $reason, User $receivedBy): Payment
    {
        return DB::transaction(function () use ($original, $reason, $receivedBy) {
            $reversal = Payment::create([
                'tenant_id' => $original->tenant_id,
                'booking_id' => $original->booking_id,
                'amount' => -$original->amount, // negative entry
                'payment_method' => $original->payment_method,
                'received_by' => $receivedBy->id,
                'type' => PaymentType::REVERSAL,
            ]);

            activity()
                ->performedOn($original->booking)
                ->causedBy(auth()->user() ?? $receivedBy)
                ->withProperties([
                    'original_payment_id' => $original->id,
                    'reversal_payment_id' => $reversal->id,
                    'amount' => $reversal->amount,
                    'reason' => $reason,
                    'new_balance' => $original->booking->fresh()->paid_amount,
                ])
                ->log('payment_reversed');

            return $reversal;
        });
    }
}
