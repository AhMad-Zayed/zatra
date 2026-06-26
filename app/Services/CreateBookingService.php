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
     * Executes the creation of a booking, processing passengers, addons, and financials.
     * 
     * @param array $data Unified booking payload
     * Expected keys: tenant_id, trip_instance_id, customer_id, passengersData, addonsData, user_id (optional creator), notes (optional)
     * @throws InventoryExhaustedException
     */
    public function execute(array $data): Booking
    {
        $tenantId = $data['tenant_id'];
        $tripInstanceId = $data['trip_instance_id'];
        $customerId = $data['customer_id'];
        $passengersData = $data['passengersData'] ?? [];
        $addonsData = $data['addonsData'] ?? [];
        $creatorUserId = $data['user_id'] ?? null;
        $notes = $data['notes'] ?? null;

        return DB::transaction(function () use ($tenantId, $tripInstanceId, $customerId, $passengersData, $addonsData, $creatorUserId, $notes) {
            
            // 1. Lock the TripInstance for update to prevent race conditions on inventory
            $tripInstance = TripInstance::where('id', $tripInstanceId)
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->firstOrFail();

            // 2. Check general inventory limits if applicable
            $requestedSeats = count($passengersData);
            if ($tripInstance->max_capacity !== null) {
                // Calculate current booked seats
                $currentBooked = Passenger::whereHas('booking', function ($q) use ($tripInstanceId) {
                    $q->where('trip_instance_id', $tripInstanceId)
                      ->whereIn('booking_status', [BookingStatus::Confirmed, BookingStatus::Pending]); // Assuming pending holds inventory
                })->count();

                if (($currentBooked + $requestedSeats) > $tripInstance->max_capacity) {
                    throw new InventoryExhaustedException("Sorry, only " . ($tripInstance->max_capacity - $currentBooked) . " seats left.");
                }
            }

            // 3. Create the Booking Record (Owner is Customer, Creator is optional User)
            $booking = Booking::create([
                'tenant_id' => $tenantId,
                'trip_instance_id' => $tripInstanceId,
                'customer_id' => $customerId, // The actual owner of the booking
                'user_id' => $creatorUserId, // Audit trail: The Admin who created this (Null for self-checkout)
                'booking_status' => BookingStatus::Pending,
                'payment_status' => PaymentStatus::Unpaid,
                'notes' => $notes,
            ]);

            $totalAmount = 0;

            // 4. Process Passengers
            foreach ($passengersData as $pData) {
                $tier = TripPricingTier::where('id', $pData['trip_pricing_tier_id'])
                            ->where('trip_instance_id', $tripInstanceId)
                            ->firstOrFail();
                            
                Passenger::create([
                    'tenant_id' => $tenantId,
                    'booking_id' => $booking->id,
                    'trip_pricing_tier_id' => $tier->id,
                    'price_at_booking' => $tier->price,
                    'dynamic_data' => $pData['dynamic_data'] ?? null,
                ]);
                
                $totalAmount += $tier->price;
            }

            // 5. Process Addons
            foreach ($addonsData as $aData) {
                $addon = TripAddon::where('id', $aData['trip_addon_id'])
                            ->where('trip_instance_id', $tripInstanceId)
                            ->lockForUpdate()
                            ->firstOrFail();
                            
                // Validate Addon Capacity if max_quantity is set
                if ($addon->max_quantity !== null) {
                    $currentAddonQty = BookingAddon::where('trip_addon_id', $addon->id)
                        ->whereHas('booking', function ($query) {
                            $query->where('booking_status', '!=', BookingStatus::Cancelled);
                        })
                        ->sum('quantity');

                    if (($currentAddonQty + $aData['quantity']) > $addon->max_quantity) {
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
