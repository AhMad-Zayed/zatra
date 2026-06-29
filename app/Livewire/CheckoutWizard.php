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
    
    public BookingForm $form;
    
    public int $currentStep = 1;
    public $paymentMethod = 'cash';
    public $paymentType = 'full';
    public $booking_id = null;
    public $wl_id = null; // Waiting List ID for conversion hook

    public function mount(Tenant $tenant, TripInstance $tripInstance)
    {
        if ($tripInstance->remaining_seats <= 0) {
            session()->flash('error', 'نأسف، لقد بيعت جميع مقاعد هذه الرحلة بالكامل.');
            $this->redirect(route('storefront.trip.details', ['tenant' => $tenant->slug, 'tripInstance' => $tripInstance->id]), navigate: true);
            return;
        }

        $this->tripInstance = $tripInstance->load('tripPassengerCategories', 'tripAddons', 'pickupRoutes.pickupPoints');
        $this->tenant = $tripInstance->tenant;
        
        // Pass the trip instance ID to the form for strict validation scoping
        $this->form->setTripInstanceId($this->tripInstance->id);
        
        // Add one default passenger row
        $this->form->addPassenger();

        // Capture Waiting List Hook
        $this->wl_id = request()->query('wl');

        if (Auth::guard('customer')->check() || session()->has('guest_session_id')) {
            $this->currentStep = 3;
        }
    }

    public function addPassenger()
    {
        if (count($this->form->passengers) >= $this->tripInstance->remaining_seats) {
            $this->addError('form.passengers', "لا يمكنك إضافة ركاب إضافيين. المقاعد المتبقية: " . $this->tripInstance->remaining_seats);
            return;
        }
        $this->form->addPassenger();
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
    }

    public function toggleAddon($addonId)
    {
        $this->form->toggleAddon($addonId);
    }

    public function submitLeadCapture()
    {
        $this->validate([
            'form.passengers.0.first_name' => 'required|string|max:255',
            'form.email' => 'required|email|max:255',
            'form.phone' => 'nullable|string|max:20',
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
        $hold = \App\Models\InventoryLedger::create([
            'trip_instance_id' => $this->tripInstance->id,
            'quantity' => -$seatsToHold,
            'type' => 'hold',
            'expires_at' => now()->addMinutes(15),
        ]);

        $guestSession->update(['hold_id' => $hold->id]);

        session()->put('guest_session_id', $guestSession->id);

        $this->currentStep = 3; // Move to Passenger details
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

        $this->currentStep = 4; // Move to Addons (or final submit)
    }

    public function submitAddons()
    {
        $this->form->validateOnly('addons');
        $this->currentStep = 5; // Move to Payment Method
    }

    public function submitBooking(CreateBookingService $bookingService)
    {
        // 1. Strict Security: Must be logged in or Guest
        if (!Auth::guard('customer')->check() && !$this->guestSession) {
            $this->currentStep = 1;
            return;
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
            
            $depositAmount = null;
            if ($this->paymentType === 'deposit' && $this->tripInstance->tripTemplate->deposit_enabled) {
                $percentage = $this->tripInstance->tripTemplate->deposit_percentage ?? 100;
                $depositAmount = ($grandTotal * $percentage) / 100;
            }

            // Compile Unified Payload Array (DTO format)
            $payload = [
                'tenant_id' => $this->tenant->id,
                'trip_instance_id' => $this->tripInstance->id,
                'customer_id' => $customerId, // Null if guest
                'guest_session_id' => $this->guestSession ? $this->guestSession->id : null,
                'hold_id' => $this->guestSession ? $this->guestSession->hold_id : null,
                'user_id' => null, // Not an admin
                'passengersData' => $this->form->passengers,
                'addonsData' => $this->form->addons,
                'payment_type' => $this->paymentType,
                'deposit_amount' => $depositAmount,
                'notes' => null,
            ];

            // Call the refactored Service
            $booking = $bookingService->execute($payload);
            $this->booking_id = $booking->id;

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
            $this->currentStep = 3; // Send them back to passenger step
        } catch (\Exception $e) {
            // Catch-all to prevent 500 crashes
            $this->form->addError('passengers', 'Something went wrong while processing your booking. Please try again later.');
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
