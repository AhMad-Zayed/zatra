<?php

namespace App\Livewire;

use Livewire\Component;
use App\Models\TripInstance;
use App\Models\Tenant;
use App\Livewire\Forms\BookingForm;
use App\Services\CustomerOtpService;
use App\Services\CreateBookingService;
use App\Exceptions\Auth\OtpCoolDownException;
use App\Exceptions\Auth\InvalidOtpException;
use App\Exceptions\InventoryExhaustedException;
use Illuminate\Validation\UnauthorizedException;
use Illuminate\Support\Facades\Auth;
use Exception;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;


#[Layout('components.layouts.storefront')]
class CheckoutWizard extends Component
{
    public TripInstance $tripInstance;
    public Tenant $tenant;
    public ?\App\Models\PackageOption $packageOption = null;
    public ?int $selectedPackageId = null;

    // Hotel/Rooming redesign Ticket 2 — built alongside the PackageOption properties above, not
    // replacing them. UI-level kill-switch gate: computed once in mount() from
    // TripInstance::room_booking_is_available (tenant setting + catalog data both required).
    // roomSelections shape: [room_type_id => ['quantity' => int, 'occupancy_type' => 'shared'|'single']].
    public bool $roomBookingAvailable = false;
    public array $roomSelections = [];

    public BookingForm $form;
    
    public int $currentStep = 1;
    public $paymentMethod = 'cash';
    public $paymentType = 'full';
    public $booking_id = null;
    public $wl_id = null; // Waiting List ID for conversion hook
    public string $idempotencyKey = '';

    public function mount(Tenant $tenant, TripInstance $tripInstance)
    {
        $this->idempotencyKey = md5(
            session()->getId() . '_' .
            ($tripInstance->id ?? 'unknown') . '_' .
            now()->format('YmdH')
        );

        if ($tripInstance->remaining_seats <= 0) {
            session()->flash('error', 'نأسف، لقد بيعت جميع مقاعد هذه الرحلة بالكامل.');
            $this->redirect(route('storefront.trip.details', ['tenant' => $tenant->slug, 'tripTemplate' => $tripInstance->tripTemplate->slug]), navigate: true);
            return;
        }

        $this->tripInstance = $tripInstance->load('tripPassengerCategories', 'tripAddons', 'pickupRoutes.pickupPoints');
        $this->tenant = $tripInstance->tenant;

        // Hotel/Rooming redesign Ticket 2 — UI-level kill-switch gate (belt-and-suspenders with
        // the backend gate in CreateBookingService::execute()). Computed once here rather than
        // per-render, since the underlying tenant setting/catalog data doesn't change mid-request.
        $this->roomBookingAvailable = $this->tripInstance->room_booking_is_available;
        if ($this->roomBookingAvailable) {
            $this->tripInstance->load('tripStayLegs.hotelOptions.roomTypes');
        }
        
        // Pass the trip instance ID to the form for strict validation scoping
        $this->form->setTripInstanceId($this->tripInstance->id);
        
        // Add one passenger row per traveler selected on trip-details' stepper (?travelers=N,
        // defaults to 1 -- unchanged for any link that doesn't pass it). Capped to
        // remaining_seats so a stale/tampered query value can't request more passenger rows than
        // the trip can actually seat; each row still just holds a category_id to be chosen here,
        // same as before.
        $travelerCount = max(1, min(
            (int) request('travelers', 1),
            $this->tripInstance->remaining_seats > 0 ? $this->tripInstance->remaining_seats : 1,
        ));
        for ($i = 0; $i < $travelerCount; $i++) {
            $this->form->addPassenger();
        }

        // Capture Waiting List Hook
        $this->wl_id = request()->query('wl');
        
        // Capture Package Option
        $this->selectedPackageId = request('package');
        if ($this->selectedPackageId) {
            $this->packageOption = \App\Models\PackageOption::find($this->selectedPackageId);
        }

        $guest = $this->guestSession;
        if ($guest && $guest->expires_at < now()) {
            session()->forget('guest_session_id');
            $guest = null;
        }

        if (Auth::guard('customer')->check() || $guest) {
            $this->currentStep = 2;

            // Refresh-resilience: a hard refresh at Step 2 previously discarded every typed
            // passenger entry (including the customer's own name, auto-filled from Step 1) while
            // the seat hold/countdown kept ticking unaffected -- live-reproduced and documented in
            // docs/STOREFRONT_UX_AUDIT.md (Friction Point #3). Session-scoped (not
            // GuestSession-scoped) so this also covers logged-in customers, who have no
            // GuestSession row at all. Only restored on a genuine resume (guest session or auth
            // check above already passed), never on a brand-new visit.
            $draft = session($this->passengersDraftSessionKey());
            if (is_array($draft) && !empty($draft)) {
                $this->form->passengers = $draft;
            }
        }
    }

    private function passengersDraftSessionKey(): string
    {
        return "checkout_passengers_draft_{$this->tripInstance->id}";
    }

    private function savePassengersDraft(): void
    {
        session()->put($this->passengersDraftSessionKey(), $this->form->passengers);
    }

    /**
     * Livewire's generic per-property update hook (fires for every wire:model commit, including
     * nested/dotted paths like "form.passengers.0.first_name") -- keeps the session draft current
     * as the customer types, without a dedicated method per field.
     */
    public function updated($name, $value)
    {
        if (str_starts_with($name, 'form.passengers')) {
            $this->savePassengersDraft();
        }
    }

    public function addPassenger()
    {
        if (count($this->form->passengers) >= 10) {
            $this->addError('form.passengers', "لا يمكن إضافة أكثر من 10 ركاب في حجز واحد.");
            return;
        }
        $seatedPassengers = 0;
        $categories = $this->tripInstance->tripPassengerCategories->keyBy('id');
        foreach ($this->form->passengers as $p) {
            $cat = $categories->get($p['trip_passenger_category_id'] ?? null);
            // Default to requiring a seat if no category is selected yet
            if (!$cat || $cat->requires_seat) {
                $seatedPassengers++;
            }
        }

        if ($seatedPassengers >= $this->tripInstance->remaining_seats) {
            $this->addError('form.passengers', "لا توجد مقاعد كافية. يرجى اختيار فئة راكب لا تحتاج مقعداً (مثل الرضع) للركاب الحاليين قبل إضافة المزيد. المقاعد المتبقية: " . $this->tripInstance->remaining_seats);
            return;
        }
        $this->form->addPassenger();
        $this->savePassengersDraft();
    }

    #[Livewire\Attributes\Computed]
    public function getAvailablePickupPointsProperty()
    {
        $points = collect();
        if ($this->tripInstance->relationLoaded('pickupRoutes')) {
            foreach ($this->tripInstance->pickupRoutes as $route) {
                $points = $points->merge($route->pickupPoints);
            }
        }
        return $points;
    }
    #[Livewire\Attributes\Computed]
    public function getGuestSessionProperty()
    {
        if (session()->has('guest_session_id')) {
            return \App\Models\GuestSession::find(session()->get('guest_session_id'));
        }
        return null;
    }

    // Hotel/Rooming redesign Ticket 2 — flat list across all legs/hotel options, each item
    // carrying its leg/hotel label for display. Deliberately minimal (a labeled quantity
    // stepper list, not a per-leg wizard step or a visual room picker) — the approved Ticket 2
    // scope for this UI.
    #[Livewire\Attributes\Computed]
    public function getAvailableRoomTypesProperty()
    {
        if (!$this->roomBookingAvailable) {
            return collect();
        }

        $rows = collect();
        foreach ($this->tripInstance->tripStayLegs as $leg) {
            foreach ($leg->hotelOptions as $option) {
                if (!$option->is_active) {
                    continue;
                }
                foreach ($option->roomTypes as $roomType) {
                    if (!$roomType->is_active) {
                        continue;
                    }
                    $rows->push([
                        'room_type' => $roomType,
                        'leg_label' => $leg->label,
                        'hotel_option_label' => $option->label ?? $option->hotel?->name,
                    ]);
                }
            }
        }

        return $rows;
    }

    public function updateRoomSelectionQuantity(int $roomTypeId, int $quantity): void
    {
        $quantity = max(0, $quantity);

        if ($quantity === 0) {
            unset($this->roomSelections[$roomTypeId]);
            return;
        }

        $this->roomSelections[$roomTypeId]['quantity'] = $quantity;
        $this->roomSelections[$roomTypeId]['occupancy_type'] ??= 'shared';
    }

    public function updateRoomSelectionOccupancy(int $roomTypeId, string $occupancyType): void
    {
        if (!isset($this->roomSelections[$roomTypeId])) {
            return;
        }
        $this->roomSelections[$roomTypeId]['occupancy_type'] = $occupancyType === 'single' ? 'single' : 'shared';
    }

    // Mirrors CreateBookingService/RoomInventoryService's pricing formula exactly, for display
    // only — the server independently recomputes and is the source of truth at booking time.
    // Public (not the per-charge total below) so the Step 3 room selector can show each room
    // type's real price at the point of choice instead of only after reaching Step 4 -- the other
    // half of Friction Point #5.
    public function roomTypePricePerRoom(\App\Models\RoomType $roomType, string $occupancyType): float
    {
        if ($occupancyType === 'single') {
            return (float) $roomType->price_adjustment_shared + (float) $roomType->price_adjustment_single_supplement;
        }

        return (float) $roomType->price_adjustment_shared * $roomType->capacity_per_room;
    }

    private function estimateRoomCharges(): float
    {
        if (!$this->roomBookingAvailable || empty($this->roomSelections)) {
            return 0.0;
        }

        $roomTypesById = $this->availableRoomTypes->pluck('room_type')->keyBy('id');
        $total = 0.0;

        foreach ($this->roomSelections as $roomTypeId => $selection) {
            $roomType = $roomTypesById->get($roomTypeId);
            if (!$roomType || empty($selection['quantity'])) {
                continue;
            }

            $occupancyType = $selection['occupancy_type'] ?? 'shared';
            $total += $this->roomTypePricePerRoom($roomType, $occupancyType) * (int) $selection['quantity'];
        }

        return $total;
    }

    /**
     * Every price display in this wizard previously hardcoded "$"/"دولار" (passengers/total) or
     * "SAR" (add-ons), regardless of the trip's actual configured currency -- live-confirmed as a
     * real customer-facing inconsistency (docs/STOREFRONT_UX_AUDIT.md, Friction Point #4). This is
     * the single source of truth the view now reads instead.
     */
    #[Livewire\Attributes\Computed]
    public function getCurrencyProperty(): string
    {
        // TripInstance.currency (not the template's) is what CreateBookingService actually
        // records on the resulting Booking -- matching it here keeps this display consistent
        // with the real currency the booking will be created in.
        return $this->tripInstance->currency ?? $this->tripInstance->tripTemplate->currency ?? 'USD';
    }

    /**
     * Step 4's order summary previously showed one line labeled "الركاب (N)" whose amount was
     * actually already the full grand total (passengers + rooms + add-ons + package, all silently
     * combined) -- a family booking's room surcharge was invisible both at Step 3's selection
     * point and here, folded into a line that claimed to be passengers-only. Live-confirmed in
     * docs/STOREFRONT_UX_AUDIT.md (Friction Point #5): a $90 room charge added at Step 3 only ever
     * showed up baked into this mislabeled total. Split into real, independent subtotals below so
     * the view can show each cost component on its own line.
     */
    #[Livewire\Attributes\Computed]
    public function getPassengersSubtotalProperty(): float
    {
        $overrideAmount = $this->tripInstance->price_override ? $this->tripInstance->price_override_amount : 0;

        $total = 0;
        $categories = $this->tripInstance->tripPassengerCategories->keyBy('id');
        foreach ($this->form->passengers as $p) {
            $tierId = $p['trip_passenger_category_id'] ?? null;
            if ($tierId && isset($categories[$tierId])) {
                $total += ($categories[$tierId]->price + $overrideAmount);
            }
        }

        return $total;
    }

    #[Livewire\Attributes\Computed]
    public function getAddonsSubtotalProperty(): float
    {
        $total = 0;
        $addons = $this->tripInstance->tripAddons->keyBy('id');
        foreach ($this->form->addons as $a) {
            $addonId = $a['trip_addon_id'] ?? null;
            if ($addonId && isset($addons[$addonId])) {
                $total += ($addons[$addonId]->price * ($a['quantity'] ?? 1));
            }
        }

        return $total;
    }

    #[Livewire\Attributes\Computed]
    public function getRoomsSubtotalProperty(): float
    {
        return $this->estimateRoomCharges();
    }

    #[Livewire\Attributes\Computed]
    public function getGrandTotalProperty()
    {
        $total = $this->passengersSubtotal + $this->addonsSubtotal + $this->roomsSubtotal;

        if ($this->packageOption) {
            $total += $this->packageOption->price_adjustment;
        }

        return max(0, $total);
    }

    public function autoFillPassenger()
    {
        if (Auth::guard('customer')->check()) {
            $customer = Auth::guard('customer')->user();
            $parts = explode(' ', trim($customer->name), 2);
            $this->form->passengers[0]['first_name'] = $parts[0] ?? '';
            $this->form->passengers[0]['last_name'] = $parts[1] ?? '';
        }
    }

    public function removePassenger($index)
    {
        $this->form->removePassenger($index);
        $this->savePassengersDraft();
    }

    public function toggleAddon($addonId)
    {
        $this->form->toggleAddon($addonId);
    }

    public function submitLeadCapture()
    {
        $this->validate([
            'form.passengers.0.first_name' => 'required|string|max:255',
            'form.passengers.0.last_name' => 'required|string|max:255',
            'form.email' => 'required|email|max:255',
            'form.phone' => ['nullable', 'regex:/^\+?[0-9]{7,15}$/'],
        ], attributes: [
            // Without these, a validation failure literally reads "صيغة form.phone غير
            // صحيحة" -- the raw internal field path leaked straight to the customer. Live-
            // confirmed, docs/STOREFRONT_UX_AUDIT.md (Quick Win #1).
            'form.passengers.0.first_name' => 'الاسم الأول',
            'form.passengers.0.last_name' => 'اسم العائلة',
            'form.email' => 'البريد الإلكتروني',
            'form.phone' => 'رقم الجوال',
        ]);

        // Create Guest Session
        $guestSession = \App\Models\GuestSession::create([
            'first_name' => $this->form->passengers[0]['first_name'],
            'email' => $this->form->email,
            'phone' => $this->form->phone,
            'trip_instance_id' => $this->tripInstance->id,
            'expires_at' => now()->addMinutes(15),
        ]);

        // Call InventoryLedger to create a hold
        // The hold needs a quantity. Initially we might just hold 1 seat, or the number of passengers they currently have.
        $seatsToHold = count($this->form->passengers);

        // Bug fix: a customer arriving via a waitlist redemption link (?wl=) used to always get
        // a second, independent hold here — the waitlist's own hold (created by
        // WaitlistAutoPromotion or the admin "send link now" action) was never reused or
        // released, so this trip briefly double-counted the same seats as held. If that waitlist
        // hold is still live, reuse it (re-stamping its expiry to match this guest session's own
        // 15-minute window) instead of creating a new one; submitPassengers() already resizes
        // whichever hold ends up on the guest session to match the real passenger count once
        // it's known. InventoryLedger rows are immutable via Eloquent (see the model's
        // `updating` guard), so — same as submitPassengers()/extendTimer() elsewhere in this
        // class — the resize below goes through the query builder, not $hold->update().
        $hold = null;
        if ($this->wl_id) {
            $waitingList = \App\Models\WaitingList::find($this->wl_id);
            if ($waitingList && $waitingList->hold_id) {
                $hold = \App\Models\InventoryLedger::where('id', $waitingList->hold_id)
                    ->where('trip_instance_id', $this->tripInstance->id)
                    ->where('type', 'hold')
                    ->where(function ($q) {
                        $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                    })
                    ->first();

                if ($hold) {
                    \App\Models\InventoryLedger::where('id', $hold->id)
                        ->update(['expires_at' => now()->addMinutes(15)]);
                }
            }
        }

        if (!$hold) {
            $hold = \App\Models\InventoryLedger::create([
                'trip_instance_id' => $this->tripInstance->id,
                'quantity' => -$seatsToHold,
                'type' => 'hold',
                'expires_at' => now()->addMinutes(15),
            ]);
        }

        $guestSession->update(['hold_id' => $hold->id]);

        session()->put('guest_session_id', $guestSession->id);

        $this->currentStep = 2; // Move to Passenger details
        $this->savePassengersDraft(); // Passenger #1's name is already pre-filled from Step 1 -- persist it immediately so an early refresh doesn't lose it.
    }

    public function extendTimer()
    {
        if ($this->guestSession) {
            $newExpiry = now()->addMinutes(10);
            $this->guestSession->update(['expires_at' => $newExpiry]);
            
            if ($this->guestSession->hold_id) {
                \App\Models\InventoryLedger::where('id', $this->guestSession->hold_id)
                    ->update(['expires_at' => $newExpiry]);
            }

            $this->dispatch('timer-extended', newTime: $newExpiry->toIso8601String());
        }
    }

    public function submitPassengers()
    {
        // Must be logged in or have a guest session
        if (!Auth::guard('customer')->check() && !$this->guestSession) {
            $this->currentStep = 1;
            return;
        }

        $this->form->validateOnly('passengers');
        $this->form->validateOnly('passengers.*.trip_passenger_category_id');

        $seatedPassengers = 0;
        $categories = $this->tripInstance->tripPassengerCategories->keyBy('id');
        foreach ($this->form->passengers as $p) {
            $cat = $categories->get($p['trip_passenger_category_id'] ?? null);
            if (!$cat || $cat->requires_seat) {
                $seatedPassengers++;
            }
        }

        if ($seatedPassengers > $this->tripInstance->remaining_seats) {
            $this->addError('form.passengers', "عدد الركاب الذين يحتاجون مقاعد ({$seatedPassengers}) يتجاوز المقاعد المتبقية ({$this->tripInstance->remaining_seats}).");
            return;
        }

        // Update hold quantity to match seated passengers
        if ($this->guestSession && $this->guestSession->hold_id) {
            \App\Models\InventoryLedger::where('id', $this->guestSession->hold_id)
                ->update(['quantity' => -$seatedPassengers]);
        }

        $this->currentStep = 3; // Move to Addons (or final submit)
    }

    public function submitAddons()
    {
        $this->form->validateOnly('addons');
        $this->currentStep = 4; // Move to Payment Method
    }

    public function submitBooking(CreateBookingService $bookingService)
    {
        // 1. Strict Security: Must be logged in or Guest
        if (!Auth::guard('customer')->check() && !$this->guestSession) {
            $this->currentStep = 1;
            return;
        }

        if ($this->paymentMethod === 'stripe') {
            abort(403, 'Stripe payments are currently disabled.');
        }

        $customerId = null;

        if (Auth::guard('customer')->check()) {
            $customer = Auth::guard('customer')->user();
            if ($customer->tenant_id !== $this->tripInstance->tenant_id) {
                throw new UnauthorizedException("Customer does not belong to this tenant.");
            }
            $customerId = $customer->id;
        }

        // 3. Final Form Validation (Ensures tiers/addons belong to this trip)
        $this->form->validate();

        // Requirement-preset pre-check — STRICT for customer self-checkout, text/date items
        // only. No document-image upload exists in this checkout form (only the post-booking
        // CustomerBookingPortal flow can collect one), so image-type items are never blocked
        // here; CreateBookingService::execute() still tags each passenger's
        // requirements_complete against ALL item types (including image) regardless of this
        // check's outcome, so an outstanding image requirement remains visible to staff even
        // though checkout itself succeeded.
        $requirementPreset = $this->tripInstance->tripTemplate->requirementPreset;
        $requirementService = app(\App\Services\RequirementValidationService::class);
        $missingRequirements = $requirementService->findMissingRequirements($requirementPreset, $this->form->passengers);
        $blockingMisses = $requirementService->blockingMisses($missingRequirements);

        if (!empty($blockingMisses)) {
            foreach ($blockingMisses as $miss) {
                $field = $miss['type'] === 'date' ? 'date_of_birth' : 'document_number';
                $this->form->addError("passengers.{$miss['passenger_index']}.{$field}", "{$miss['label']} مطلوب لإتمام الحجز.");
            }
            $this->currentStep = 2;
            return;
        }

        try {
            // Calculate total amount to find deposit if applicable
            $grandTotal = 0;
            $overrideAmount = $this->tripInstance->price_override ? $this->tripInstance->price_override_amount : 0;
            
            $categoryIds = collect($this->form->passengers)->pluck('trip_passenger_category_id');
            $addonIds    = collect($this->form->addons)->pluck('trip_addon_id');

            $categories = \App\Models\TripPassengerCategory::whereIn('id', $categoryIds)->get()->keyBy('id');
            $addons     = \App\Models\TripAddon::whereIn('id', $addonIds)->get()->keyBy('id');

            foreach ($this->form->passengers as $p) {
                $tier = $categories[$p['trip_passenger_category_id']] ?? null;
                if ($tier) {
                    $grandTotal += ($tier->price + $overrideAmount);
                }
            }
            foreach ($this->form->addons as $a) {
                $addon = $addons[$a['trip_addon_id']] ?? null;
                if ($addon) {
                    $grandTotal += ($addon->price * $a['quantity']);
                }
            }
            
            $packageAdj = $this->packageOption?->price_adjustment ?? 0;
            $grandTotal += $packageAdj;
            
            $depositAmount = null;
            if ($this->paymentType === 'deposit' && $this->tripInstance->tripTemplate->deposit_enabled) {
                $percentage = $this->tripInstance->tripTemplate->deposit_percentage ?? 100;
                $depositAmount = ($grandTotal * $percentage) / 100;
            }

            // Hotel/Rooming redesign Ticket 2 — UI-level kill-switch gate: send an empty array
            // whenever $roomBookingAvailable is false, regardless of any stray client-side
            // state, so a disabled feature genuinely sends nothing. CreateBookingService::execute()
            // independently re-checks the tenant setting itself either way (backend gate,
            // belt-and-suspenders — this UI-level gate is not the only thing standing between a
            // disabled switch and a consumed room).
            $roomSelectionsPayload = [];
            if ($this->roomBookingAvailable) {
                foreach ($this->roomSelections as $roomTypeId => $selection) {
                    if (empty($selection['quantity'])) {
                        continue;
                    }
                    $roomSelectionsPayload[] = [
                        'room_type_id' => (int) $roomTypeId,
                        'quantity' => (int) $selection['quantity'],
                        'occupancy_type' => $selection['occupancy_type'] ?? 'shared',
                    ];
                }
            }

            // Compile Unified Payload Array (DTO format)
            $payload = [
                'tenant_id' => $this->tenant->id,
                'trip_instance_id' => $this->tripInstance->id,
                'customer_id' => $customerId, // Null if guest
                'guest_session_id' => $this->guestSession ? $this->guestSession->id : null,
                'hold_id' => $this->guestSession ? $this->guestSession->hold_id : null,
                'user_id' => null, // Not an admin
                'package_option_id' => $this->selectedPackageId,
                'passengersData' => $this->form->passengers,
                'addonsData' => $this->form->addons,
                'room_selections' => $roomSelectionsPayload,
                'payment_type' => $this->paymentType,
                'deposit_amount' => $depositAmount,
                'idempotency_key' => $this->idempotencyKey,
                'notes' => null,
            ];

            // Call the refactored Service
            $booking = $bookingService->execute($payload);
            $this->booking_id = $booking->id;
            session()->forget($this->passengersDraftSessionKey());

            // Phase 13: Conversion Hook - Mark Waiting List as Converted
            if ($this->wl_id) {
                \App\Models\WaitingList::where('id', $this->wl_id)
                    ->where('status', \App\Enums\WaitingListStatusEnum::Notified)
                    ->update(['status' => \App\Enums\WaitingListStatusEnum::Converted]);
            }

            if (in_array($this->paymentMethod, ['cash', 'transfer'])) {
                // Set expiry time based on tenant settings
                $expiryHours = $this->tenant->cash_booking_expiry_hours ?? 24;
                if ($expiryHours > 0) {
                    $booking->update([
                        'expires_at' => now()->addHours($expiryHours)
                    ]);
                }
                
                // Branch 1: Cash at Office (Bypass Gateway)
                $this->redirectRoute('booking.success', ['tenant' => $this->tenant->slug, 'uuid' => $booking->uuid], navigate: true);
                return;
            }

            // Branch 2: Online Payment Gateway
            $gatewayName = $this->tenant->payment_gateway_provider ?? 'stripe';
            $gateway = \App\Services\Payments\PaymentManager::resolve($gatewayName, $this->tenant);
            
            $paymentSession = $gateway->initializePayment($booking, $booking->grand_total);

            // Redirect the customer to the Gateway's hosted checkout page
            return redirect()->away($paymentSession['gateway_url']);

        } catch (InventoryExhaustedException $e) {
            $this->form->addError('passengers', $e->getMessage());
            $this->currentStep = 2; // Send them back to passenger step
        } catch (\Exception $e) {
            // Catch-all to prevent 500 crashes. Defense-in-depth only: the specific,
            // live-reproduced cause of this firing (a passenger submitted with no category
            // selected -- 'trip_passenger_category_id' was 'nullable' instead of 'required' in
            // BookingForm::rules(), so CreateBookingService::execute()'s
            // TripPassengerCategory::where('id', null)->firstOrFail() threw a
            // ModelNotFoundException) is now actually prevented upstream by that validation fix,
            // not just hidden behind a nicer message here. This still shows a real, actionable
            // Arabic message rather than raw English for whatever else might reach this catch.
            $this->form->addError('passengers', 'حدث خطأ أثناء معالجة حجزك، يرجى المحاولة مرة أخرى.');
            // Log the exception in production
            \Illuminate\Support\Facades\Log::error('Checkout Error: ' . $e->getMessage());
        }
    }



    public function render()
    {
        $booking = $this->booking_id ? \App\Models\Booking::with('passengers.tripPricingTier', 'bookingAddons.tripAddon')->find($this->booking_id) : null;

        return view('livewire.checkout-wizard', [
            'booking' => $booking
        ]);
    }
}
