<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\TripInstance;
use App\Models\User;
use App\Models\Passenger;
use App\Enums\BookingStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BookingService
{
    /**
     * Create a new booking
     *
     * @param TripInstance $instance
     * @param User $customer
     * @param array $passengersData Array of passengers, each with: name, passport_number, special_requirements (optional), passport (optional file/path), national_id (optional file/path)
     * @param float $totalAmount
     * @param array $additionalData Additional fields like flight_details, hotel_details, insurance_details, visa_details
     * @return Booking
     */
    public function createBooking(TripInstance $instance, User $customer, array $passengersData, float $totalAmount, array $additionalData = []): Booking
    {
        $newPassengerCount = count($passengersData);
        if ($newPassengerCount === 0) {
            throw new \InvalidArgumentException("A booking must have at least one passenger.");
        }

        if (!app()->environment('testing')) {
            $key = 'create-booking:' . $customer->id;
            if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($key, 3)) {
                $seconds = \Illuminate\Support\Facades\RateLimiter::availableIn($key);
                throw new \RuntimeException("لقد تجاوزت الحد الأقصى لإنشاء الحجوزات. يرجى الانتظار {$seconds} ثانية.");
            }
            \Illuminate\Support\Facades\RateLimiter::hit($key, 60);
        }

        return DB::transaction(function () use ($instance, $customer, $passengersData, $totalAmount, $additionalData, $newPassengerCount) {
            // 1. Capacity check
            $this->ensureCapacity($instance, $newPassengerCount);

            // 2. Reference generation
            $tenant = $instance->tenant;
            $reference = $this->generateReference($tenant);

            // 3. Create booking record in pending state
            $booking = Booking::create([
                'tenant_id' => $instance->tenant_id,
                'user_id' => $customer->id,
                'trip_instance_id' => $instance->id,
                'reference' => $reference,
                'status' => BookingStatus::PENDING,
                'total_amount' => $totalAmount,
                'flight_details' => $additionalData['flight_details'] ?? null,
                'hotel_details' => $additionalData['hotel_details'] ?? null,
                'insurance_details' => $additionalData['insurance_details'] ?? null,
                'visa_details' => $additionalData['visa_details'] ?? null,
            ]);

            // 4. Create passengers
            foreach ($passengersData as $px) {
                $passenger = $booking->passengers()->create([
                    'tenant_id' => $instance->tenant_id,
                    'name' => $px['name'],
                    'passport_number' => $px['passport_number'],
                    'special_requirements' => $px['special_requirements'] ?? null,
                ]);

                // 5. Upload passenger documents via Spatie MediaLibrary
                if (isset($px['passport'])) {
                    if (is_string($px['passport'])) {
                        $passenger->addMedia($px['passport'])->preservingOriginal()->toMediaCollection('passport');
                    } else {
                        $passenger->addMedia($px['passport'])->toMediaCollection('passport');
                    }
                }

                if (isset($px['national_id'])) {
                    if (is_string($px['national_id'])) {
                        $passenger->addMedia($px['national_id'])->preservingOriginal()->toMediaCollection('national_id');
                    } else {
                        $passenger->addMedia($px['national_id'])->toMediaCollection('national_id');
                    }
                }
            }

            // 6. Log activity
            activity()
                ->performedOn($booking)
                ->causedBy(auth()->user() ?? $customer)
                ->withProperties([
                    'trip_instance_id' => $instance->id,
                    'passenger_count' => $newPassengerCount,
                    'total_amount' => $totalAmount,
                ])
                ->log('booking_created');

            return $booking;
        });
    }

    /**
     * Cancel a booking
     */
    public function cancelBooking(Booking $booking, ?string $reason = null): void
    {
        DB::transaction(function () use ($booking, $reason) {
            $oldStatus = $booking->status;
            
            // Bypass observers
            DB::table('bookings')
                ->where('id', $booking->id)
                ->update(['status' => BookingStatus::CANCELLED->value]);

            activity()
                ->performedOn($booking)
                ->causedBy(auth()->user())
                ->withProperties([
                    'old_status' => $oldStatus?->value,
                    'new_status' => BookingStatus::CANCELLED->value,
                    'reason' => $reason,
                ])
                ->log('booking_cancelled');
        });
    }

    /**
     * Recalculate Booking financial status based on payments
     */
    public function recalculateFinancialStatus(Booking $booking): void
    {
        // Don't update if cancelled or completed
        if (in_array($booking->status, [BookingStatus::CANCELLED, BookingStatus::COMPLETED])) {
            return;
        }

        $paid = (float) $booking->payments()->sum('amount');
        $total = (float) $booking->total_amount;

        // Derive status
        $newStatus = match (true) {
            $paid <= 0 => BookingStatus::PENDING,
            $paid >= $total => BookingStatus::PAID,
            default => BookingStatus::PARTIAL,
        };

        // Don't downgrade from confirmed to partial/pending unless required
        if ($booking->status === BookingStatus::CONFIRMED) {
            return;
        }

        if ($booking->status !== $newStatus) {
            DB::table('bookings')
                ->where('id', $booking->id)
                ->update(['status' => $newStatus->value]);

            if ($newStatus === BookingStatus::PAID || $newStatus === BookingStatus::CONFIRMED) {
                $booking->user->notify(new \App\Notifications\BookingConfirmed($booking));
            }

            activity()
                ->performedOn($booking)
                ->withProperties([
                    'old' => $booking->status?->value,
                    'new' => $newStatus->value,
                ])
                ->log('booking_status_recalculated');
        }
    }

    /**
     * Ensure trip has capacity
     */
    public function ensureCapacity(TripInstance $instance, int $newPassengerCount = 1): void
    {
        $confirmedCount = Passenger::whereHas('booking', function ($q) use ($instance) {
            $q->where('trip_instance_id', $instance->id)
              ->where('status', '!=', BookingStatus::CANCELLED->value);
        })->count();

        if (($confirmedCount + $newPassengerCount) > $instance->available_seats) {
            throw new \RuntimeException("Trip instance {$instance->id} is at capacity.");
        }
    }

    /**
     * Generate unique booking reference
     */
    private function generateReference(\App\Models\Tenant $tenant): string
    {
        $slug = Str::slug($tenant->name);
        $prefix = strtoupper(substr($slug, 0, 3));
        if (empty($prefix)) {
            $prefix = 'ZAT';
        }
        $year = now()->format('y');
        $seq = str_pad(
            Booking::where('tenant_id', $tenant->id)->count() + 1,
            5,
            '0',
            STR_PAD_LEFT
        );
        return "{$prefix}-{$year}-{$seq}";
    }
}
