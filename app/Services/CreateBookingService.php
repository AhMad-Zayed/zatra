<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Passenger;
use App\Models\BookingAddon;
use App\Models\TripInstance;
use App\Models\PackageOption;
use App\Models\TripAddon;
use App\Models\TripPassengerCategory;
use App\Exceptions\InventoryExhaustedException;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
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
     * Expected keys: tenant_id, trip_instance_id, customer_id, passengersData, addonsData, user_id (optional creator), notes (optional),
     * room_selections (optional, Hotel/Rooming Ticket 2 — [['room_type_id'=>int,'quantity'=>int,'occupancy_type'=>'shared'|'single'], ...])
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

        return DB::transaction(function () use ($data, $tenantId, $tripInstanceId, $customerId, $passengersData, $addonsData, $creatorUserId, $notes) {
            
            // 1. Lock the TripInstance for update to prevent race conditions on inventory
            $tripInstance = TripInstance::where('id', $tripInstanceId)
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->firstOrFail();

            // 2. Check general inventory limits if applicable
            $requestedSeats = 0;
            $categories = TripPassengerCategory::where('trip_instance_id', $tripInstanceId)
                ->whereIn('id', collect($passengersData)->pluck('trip_passenger_category_id')->filter())
                ->get()
                ->keyBy('id');

            foreach ($passengersData as $pData) {
                $catId = $pData['trip_passenger_category_id'] ?? null;
                $cat = $categories->get($catId);
                // Default to requiring a seat if no category or if category requires seat
                if (!$cat || $cat->requires_seat) {
                    $requestedSeats++;
                }
            }
            
            // Check if we are checking out from a guest session hold
            $holdId = $data['hold_id'] ?? null;
$hold = null;
if ($holdId) { $hold = \App\Models\InventoryLedger::find($holdId); }
            
            if ($holdId) {
                $hold = \App\Models\InventoryLedger::find($holdId);
            }

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
                'package_option_id' => $data['package_option_id'] ?? null,
                'customer_id' => $customerId, // The actual owner of the booking
                'user_id' => $creatorUserId, // Audit trail: The Admin who created this (Null for self-checkout)
                'pnr' => $pnr,
                'currency' => $tripInstance->currency ?? 'USD', // Inherit currency from TripInstance
                'booking_status' => $bookingStatus,
                'payment_status' => PaymentStatus::Unpaid,
                'payment_type' => $paymentType,
                'deposit_amount' => $depositAmount,
                'notes' => $notes,
            ]);

            $totalAmount = 0;
            $overrideAmount = $tripInstance->price_override ? $tripInstance->price_override_amount : 0;

            // Requirement-preset tracking: computed once for the whole passenger set here and
            // persisted per-passenger via requirements_complete below. This runs unconditionally
            // for every entry point (strict or permissive) — this method never blocks on it;
            // whether a caller blocks BEFORE reaching here (CheckoutWizard, on text/date misses
            // only) is entirely their own pre-check against the same shared service. A passenger
            // can therefore pass a strict pre-check and still land here with
            // requirements_complete = false if an image-type item is outstanding, since no
            // booking-time entry point can collect a document image yet — only the post-booking
            // CustomerBookingPortal flow can, which clears the flag once it does.
            $requirementPreset = $tripInstance->tripTemplate?->requirementPreset;
            $requirementService = app(\App\Services\RequirementValidationService::class);
            $missingRequirements = $requirementService->findMissingRequirements($requirementPreset, $passengersData);

            // 4. Process Passengers
            // Phone booking mode: passengersData may contain seat-allocation entries without names.
            // In this case we create placeholder passenger records (data_complete = false).
            $isPhoneBooking = $data['phone_booking_mode'] ?? false;

            \App\Models\Passenger::withoutEvents(function () use (&$passengersData, &$tripInstanceId, &$tenantId, &$booking, &$tripInstance, &$totalAmount, &$isPhoneBooking, &$overrideAmount, &$requirementService, &$missingRequirements) {
            foreach ($passengersData as $index => $pData) {
                $tier = TripPassengerCategory::where('id', $pData['trip_passenger_category_id'])
                            ->where('trip_instance_id', $tripInstanceId)
                            ->firstOrFail();

                $humanIndex = $index + 1;
                $isIncomplete = $isPhoneBooking && empty($pData['first_name']);

                $finalPrice = $tripInstance->price_override ? $overrideAmount : $tier->price;

                $passenger = Passenger::create([
                    'tenant_id'                  => $tenantId,
                    'booking_id'                 => $booking->id,
                    'trip_passenger_category_id' => $tier->id,
                    'price_at_booking'           => $finalPrice,
                    'first_name'                 => $pData['first_name'] ?? null,
                    'last_name'                  => $pData['last_name'] ?? null,
                    'document_type'              => $pData['document_type'] ?? null,
                    'document_number'            => $pData['document_number'] ?? null,
                    'date_of_birth'              => $pData['date_of_birth'] ?? null,
                    'extra_preferences'          => is_array($pData['extra_preferences'] ?? null) ? $pData['extra_preferences'] : [],
                    // Phone booking placeholders:
                    'data_complete'              => !$isIncomplete,
                    'requirements_complete'      => $requirementService->isPassengerComplete($missingRequirements, $index),
                    'passenger_label'            => $isIncomplete ? "راكب {$humanIndex} ({$tier->name})" : null,
                ]);

                if (!empty($pData['pickup_point_id'])) {
                    \App\Models\BookingPickup::create([
                        'booking_id'      => $booking->id,
                        'pickup_point_id' => $pData['pickup_point_id'],
                        'passenger_id'    => $passenger->id,
                    ]);
                }
                
                $totalAmount += $finalPrice;
            }
            });
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

            // Calculate Package Adjustment and Validate
            $packageAdjustment = 0;
            if (!empty($data['package_option_id'])) {
                $package = PackageOption::lockForUpdate()->find($data['package_option_id']);
                
                if ($package && $package->remaining_seats < count($passengersData)) {
                    throw new \App\Exceptions\InsufficientSeatsException('لا توجد مقاعد كافية في هذه الباقة');
                }
                
                $packageAdjustment = $package?->price_adjustment ?? 0;
                $totalAmount += ($packageAdjustment * count($passengersData));
            }

            // Hotel/Rooming redesign Ticket 2 — Process Room Selections (booking-time room-TYPE
            // quantity/occupancy only, no per-passenger assignment yet — Ticket 3). Built
            // entirely alongside the PackageOption block above, which stays untouched; a trip is
            // configured with one system or the other, never both in practice, and this block
            // simply does nothing if $data['room_selections'] is empty.
            //
            // Kill-switch (backend enforcement, belt-and-suspenders with the UI-level gate in
            // CheckoutWizard): tenants.settings['room_booking_enabled'] defaults to false
            // (opt-in per tenant). If a room_selections payload arrives while the switch is off
            // — a stale UI, a direct API call, anything — it is silently ignored (not rejected
            // with an error) so a booking can still be created normally minus rooms; the whole
            // point of a kill switch is to make the feature behave as if it never existed, not
            // to turn a safety toggle into a new way for bookings to start failing.
            $roomSelectionsData = $data['room_selections'] ?? [];
            if (!empty($roomSelectionsData)) {
                $roomBookingEnabled = (bool) ($tripInstance->tenant?->settings['room_booking_enabled'] ?? false);

                if (!$roomBookingEnabled) {
                    \Illuminate\Support\Facades\Log::warning(
                        "Ignored room_selections payload for tenant {$tenantId}: room_booking_enabled is off.",
                        ['trip_instance_id' => $tripInstanceId, 'booking_id' => $booking->id]
                    );
                } else {
                    // Lock ordering: TripInstance is already locked above (step 1) before this
                    // point is ever reached — RoomInventoryService locks RoomType rows second,
                    // per its own documented lock-ordering contract. Do not reverse this.
                    $resolvedRoomSelections = app(\App\Services\RoomInventoryService::class)
                        ->consumeForBooking($booking, $roomSelectionsData);

                    foreach ($resolvedRoomSelections as $resolved) {
                        $roomType = $resolved['room_type'];
                        $quantity = $resolved['quantity'];
                        $occupancyType = $resolved['occupancy_type'];

                        // Pricing formula (confirmed): price_adjustment_shared (per person) +
                        // price_adjustment_single_supplement (flat, only when occupancy_type =
                        // single), per room. Shared occupancy assumes the room's full
                        // capacity_per_room is occupied.
                        $perRoomCharge = (float) $roomType->price_adjustment_shared;
                        if ($occupancyType === 'single') {
                            $perRoomCharge += (float) $roomType->price_adjustment_single_supplement;
                        } else {
                            $perRoomCharge *= $roomType->capacity_per_room;
                        }

                        $selectionTotal = $perRoomCharge * $quantity;

                        \App\Models\BookingRoomSelection::create([
                            'tenant_id' => $tenantId,
                            'booking_id' => $booking->id,
                            'room_type_id' => $roomType->id,
                            'quantity' => $quantity,
                            'occupancy_type' => $occupancyType,
                            'price_at_booking' => $selectionTotal,
                        ]);

                        $totalAmount += $selectionTotal;
                    }
                }
            }

            // Apply Discount
            $discountAmount = $data['discount_amount'] ?? 0;
            $totalAmount = max(0, $totalAmount - $discountAmount);

            // 6. Update Final Totals and Snapshots
            $booking->update([
                'grand_total' => $totalAmount,
                'balance_due' => $totalAmount,
                'discount_amount' => $discountAmount,
                'snapshot_trip_title' => $tripInstance->tripTemplate?->title ?? 'Unknown Trip',
                'snapshot_template_name' => $tripInstance->tripTemplate?->title ?? 'Unknown Template',
                'snapshot_start_date' => $tripInstance->start_date,
                'snapshot_end_date' => $tripInstance->end_date,
                'snapshot_currency' => $tripInstance->tenant->currency ?? 'USD',
                'snapshot_total_price' => $totalAmount,
                'snapshot_taxes' => 0, // Simplified for now
                'snapshot_discounts' => 0, // Simplified for now
                'snapshot_passenger_rules' => $tripInstance->tripTemplate?->passenger_requirements ?? [],
            ]);

            // Dispatch Event for Background Notifications
            event(new \App\Events\BookingCreated($booking));

            
            // Consume Inventory
            $seatsToConsume = collect($passengersData)->filter(function ($pData) {
                $tier = \App\Models\TripPassengerCategory::find($pData['trip_passenger_category_id'] ?? null);
                return $tier && $tier->requires_seat;
            })->count();
            
            app(\App\Services\InventoryService::class)->consumeForBooking($booking, $seatsToConsume, $hold);
            
            // Recalculate totals centrally
            app(\App\Services\BookingService::class)->recalculateTotals($booking);
            
                        // 7. Process Initial Payment (if provided) — delegates to the canonical
            // BookingService::recordPayment() (the same currency-check + recalculateTotals()
            // pattern every other payment-creation path uses, P0-6) instead of a raw
            // Payment::create() with hand-rolled math. Bug fix: currency now comes from
            // $booking->currency (== $tripInstance->currency, set above) instead of
            // $tripInstance->tenant->currency — the two can legitimately differ (a tenant can
            // run trips in more than one currency), which previously mislabeled the payment.
            // recordPayment()'s own balance guard (amount must not exceed balance_due, which
            // equals grand_total here since this is the booking's first-ever payment) replaces
            // the manual pre-check this used to do inline, with the same reject-and-roll-back-
            // the-whole-booking-creation behavior since it's still inside this method's
            // transaction.
            $initialPaymentAmount = (float) ($data['initial_payment_amount'] ?? 0);
            if ($initialPaymentAmount > 0) {
                $initialPaymentType = ($paymentType === 'deposit') ? PaymentType::DEPOSIT : PaymentType::FULL;

                app(\App\Services\BookingService::class)->recordPayment(
                    $booking,
                    $initialPaymentAmount,
                    $data['initial_payment_method'] ?? 'cash',
                    $creatorUserId ? \App\Models\User::find($creatorUserId) : null,
                    $initialPaymentType,
                    null,
                    $booking->currency
                );
            }
            
            return $booking;

        });
    }
}
