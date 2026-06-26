<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Passenger;
use App\Models\BookingAddon;
use App\Models\TripInstance;
use App\Models\TripAddon;
use App\Models\TripPricingTier;
use App\Exceptions\InventoryExhaustedException;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use Illuminate\Support\Facades\DB;
use Exception;

class CreateBookingService
{
    /**
     * Create a new booking with pessimistic locking for inventory.
     *
     * @param int $tenantId
     * @param int $tripInstanceId
     * @param int $userId
     * @param array $passengersData Array of ['trip_pricing_tier_id' => int, 'dynamic_data' => array]
     * @param array $addonsData Array of ['trip_addon_id' => int, 'quantity' => int]
     * @param string|null $notes
     * @return Booking
     * @throws InventoryExhaustedException
     */
    public function execute(
        int $tenantId,
        int $tripInstanceId,
        int $userId,
        array $passengersData,
        array $addonsData = [],
        ?string $notes = null
    ): Booking {
        return DB::transaction(function () use (
            $tenantId,
            $tripInstanceId,
            $userId,
            $passengersData,
            $addonsData,
            $notes
        ) {
            // 1. Lock the TripInstance for update
            $tripInstance = TripInstance::where('id', $tripInstanceId)
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->firstOrFail();

            // 2. Validate Seat Capacity
            $requestedSeats = count($passengersData);
            
            // Calculate currently booked seats for this instance
            // Assuming bookings that are not cancelled count towards capacity
            $currentBookedSeats = Passenger::whereHas('booking', function ($query) use ($tripInstanceId) {
                $query->where('trip_instance_id', $tripInstanceId)
                      ->where('booking_status', '!=', BookingStatus::Cancelled);
            })->count();

            if (($currentBookedSeats + $requestedSeats) > $tripInstance->available_seats) {
                throw new InventoryExhaustedException("Trip is fully booked. Not enough seats available.");
            }

            // 3. Create the Booking shell
            $booking = Booking::create([
                'tenant_id' => $tenantId,
                'trip_instance_id' => $tripInstanceId,
                'user_id' => $userId,
                'booking_status' => BookingStatus::Pending,
                'payment_status' => PaymentStatus::Unpaid,
                'notes' => $notes,
            ]);

            $grandTotal = 0;

            // 4. Process Passengers
            foreach ($passengersData as $pData) {
                $tierId = $pData['trip_pricing_tier_id'];
                
                $tier = TripPricingTier::where('id', $tierId)
                    ->where('trip_instance_id', $tripInstanceId)
                    ->firstOrFail();

                Passenger::create([
                    'tenant_id' => $tenantId,
                    'booking_id' => $booking->id,
                    'trip_pricing_tier_id' => $tier->id,
                    'price_at_booking' => $tier->price, // Snapshot
                    'dynamic_data' => $pData['dynamic_data'] ?? null,
                ]);

                $grandTotal += $tier->price;
            }

            // 5. Process Addons with Pessimistic Locking
            foreach ($addonsData as $aData) {
                $addonId = $aData['trip_addon_id'];
                $requestedQty = $aData['quantity'];

                if ($requestedQty <= 0) continue;

                $addon = TripAddon::where('id', $addonId)
                    ->where('trip_instance_id', $tripInstanceId)
                    ->lockForUpdate()
                    ->firstOrFail();

                // Validate Addon Capacity if max_quantity is set
                if ($addon->max_quantity !== null) {
                    $currentAddonQty = BookingAddon::where('trip_addon_id', $addonId)
                        ->whereHas('booking', function ($query) {
                            $query->where('booking_status', '!=', BookingStatus::Cancelled);
                        })
                        ->sum('quantity');

                    if (($currentAddonQty + $requestedQty) > $addon->max_quantity) {
                        throw new InventoryExhaustedException("Addon '{$addon->name}' has insufficient quantity available.");
                    }
                }

                BookingAddon::create([
                    'tenant_id' => $tenantId,
                    'booking_id' => $booking->id,
                    'trip_addon_id' => $addon->id,
                    'quantity' => $requestedQty,
                    'price_at_booking' => $addon->price, // Snapshot
                ]);

                $grandTotal += ($addon->price * $requestedQty);
            }

            // 6. Update Final Totals
            $booking->update([
                'grand_total' => $grandTotal,
                'balance_due' => $grandTotal, // total_paid is 0
            ]);

            return $booking;
        });
    }
}
