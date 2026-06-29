<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Passenger;
use App\Models\BookingAddon;
use App\Models\TripInstance;
use App\Models\TripAddon;
use App\Models\TripPassengerCategory;
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
            
            // Check if we are checking out from a guest session hold
            $holdId = $data['hold_id'] ?? null;
            $hold = null;
            
            if ($holdId) {
                $hold = \App\Models\InventoryLedger::find($holdId);
            }

            if ($tripInstance->available_seats !== null && !$hold) {
                $available = \App\Models\InventoryLedger::where('trip_instance_id', $tripInstanceId)
                    ->where(function ($q) {
                        $q->whereNull('expires_at')
                          ->orWhere('expires_at', '>', now());
                    })
                    ->sum('quantity');

                if ($available < $requestedSeats) {
                    throw new \App\Exceptions\InsufficientSeatsException("Sorry, only " . $available . " seats left.");
                }

                $hold = \App\Models\InventoryLedger::create([
                    'trip_instance_id' => $tripInstanceId,
                    'quantity' => -$requestedSeats,
                    'type' => 'confirmed',
                ]);
            } else if ($hold) {
                // Convert the existing hold to a confirmed entry
                $hold->update([
                    'type' => 'confirmed',
                    'expires_at' => null,
                    'quantity' => -$requestedSeats // update to final quantity
                ]);
            }

            // Generate a unique PNR
            $pnr = 'ZTR-' . strtoupper(\Illuminate\Support\Str::random(6));
            while (Booking::where('pnr', $pnr)->exists()) {
                $pnr = 'ZTR-' . strtoupper(\Illuminate\Support\Str::random(6));
            }

            // Determine initial status based on payment_type (but wait, typically it's pending until they actually pay the deposit on the gateway)
            // The prompt says "When deposit is chosen, create the booking with payment_type='deposit' and deposit_amount set. The booking status should be 'confirmed_partial' not 'pending'."
            // Actually, if it's cash or transfer, maybe it becomes confirmed_partial immediately? Or maybe the prompt means the final status. I'll set it as requested or keep it pending until payment is confirmed.
            // The user says "The booking status should be 'confirmed_partial' not 'pending'." - I will set it to confirmed_partial if they select deposit and payment method is cash/transfer. If it's a gateway, it stays pending until paid?
            // "create the booking with payment_type='deposit' and deposit_amount set. The booking status should be 'confirmed_partial' not 'pending'." Let's follow this strictly.
            
            $paymentType = $data['payment_type'] ?? 'full';
            $depositAmount = $data['deposit_amount'] ?? null;
            $bookingStatus = ($paymentType === 'deposit') ? \App\Enums\BookingStatus::ConfirmedPartial : BookingStatus::Pending;

            if (!$customerId) {
                $guestSession = \App\Models\GuestSession::find($data['guest_session_id'] ?? null);
                if ($guestSession) {
                    $customer = \App\Models\Customer::firstOrCreate(
                        ['email' => $guestSession->email, 'tenant_id' => $tenantId],
                        ['name' => $guestSession->first_name, 'phone' => $guestSession->phone]
                    );
                    $customerId = $customer->id;

                    // Generate Magic Link
                    $magicLink = \Illuminate\Support\Facades\URL::temporarySignedRoute(
                        'login.magic', 
                        now()->addHours(24), 
                        ['email' => $customer->email, 'tenant_id' => $tenantId]
                    );
                    \Illuminate\Support\Facades\Log::info("Magic Login Link for {$customer->email}: {$magicLink}");
                } else {
                    throw new \Exception("Customer ID missing and no guest session found.");
                }
            }

            // 3. Create the Booking Record (Owner is Customer, Creator is optional User)
            $booking = Booking::create([
                'tenant_id' => $tenantId,
                'trip_instance_id' => $tripInstanceId,
                'customer_id' => $customerId, // The actual owner of the booking
                'user_id' => $creatorUserId, // Audit trail: The Admin who created this (Null for self-checkout)
                'pnr' => $pnr,
                'booking_status' => $bookingStatus,
                'payment_status' => PaymentStatus::Unpaid,
                'payment_type' => $paymentType,
                'deposit_amount' => $depositAmount,
                'notes' => $notes,
            ]);

            $totalAmount = 0;
            $overrideAmount = $tripInstance->price_override ? $tripInstance->price_override_amount : 0;

            if ($hold) {
                $hold->update(['booking_id' => $booking->id]);
            }

            // 4. Process Passengers
            foreach ($passengersData as $pData) {
                $tier = TripPassengerCategory::where('id', $pData['trip_passenger_category_id'])
                            ->where('trip_instance_id', $tripInstanceId)
                            ->firstOrFail();
                            
                $passenger = Passenger::create([
                    'tenant_id' => $tenantId,
                    'booking_id' => $booking->id,
                    'trip_passenger_category_id' => $tier->id,
                    'price_at_booking' => $tier->price + $overrideAmount,
                    'first_name' => $pData['first_name'] ?? null,
                    'last_name' => $pData['last_name'] ?? null,
                    'document_type' => $pData['document_type'] ?? null,
                    'document_number' => $pData['document_number'] ?? null,
                    'date_of_birth' => $pData['date_of_birth'] ?? null,
                    'extra_preferences' => is_array($pData['extra_preferences'] ?? null) ? $pData['extra_preferences'] : [],
                ]);

                if (!empty($pData['pickup_point_id'])) {
                    \App\Models\BookingPickup::create([
                        'booking_id' => $booking->id,
                        'pickup_point_id' => $pData['pickup_point_id'],
                        'passenger_id' => $passenger->id,
                    ]);
                }
                
                $totalAmount += ($tier->price + $overrideAmount);
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
                    'quantity' => $aData['quantity'],
                    'price_at_booking' => $addon->price, // Snapshot
                ]);

                $totalAmount += ($addon->price * $aData['quantity']);
            }

            // 6. Update Final Totals
            $booking->update([
                'grand_total' => $totalAmount,
                'balance_due' => $totalAmount,
            ]);

            // Dispatch Event for Background Notifications
            event(new \App\Events\BookingCreated($booking));

            return $booking;
        });
    }
}
