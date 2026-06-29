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
use function Spatie\LaravelPdf\Support\pdf;
use Spatie\Browsershot\Browsershot;

#[Layout('components.layouts.storefront')]
class CheckoutWizard extends Component
{
    public TripInstance $tripInstance;
    public Tenant $tenant;
    
    public BookingForm $form;
    
    public int $currentStep = 1;
    public $paymentMethod = 'cash';
    public $booking_id = null;
    public $wl_id = null; // Waiting List ID for conversion hook

    public function mount(Tenant $tenant, TripInstance $tripInstance)
    {
        if ($tripInstance->remaining_seats <= 0) {
            session()->flash('error', 'نأسف، لقد بيعت جميع مقاعد هذه الرحلة بالكامل.');
            $this->redirect(route('storefront.trip.details', ['tenant' => $tenant->slug, 'tripInstance' => $tripInstance->id]), navigate: true);
            return;
        }

        $this->tenant = $tenant;
        $this->tripInstance = $tripInstance;
        
        // Pass the trip instance ID to the form for strict validation scoping
        $this->form->setTripInstanceId($tripInstance->id);
        
        // Add one default passenger row
        $this->form->addPassenger();

        // Capture Waiting List Hook
        $this->wl_id = request()->query('wl');

        if (Auth::guard('customer')->check()) {
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

    public function removePassenger($index)
    {
        $this->form->removePassenger($index);
    }

    public function toggleAddon($addonId)
    {
        $this->form->toggleAddon($addonId);
    }

    public function submitPhone(CustomerOtpService $otpService)
    {
        $this->form->validateOnly('phone');
        
        try {
            $otpService->sendOtp($this->tenant, $this->form->phone);
            $this->currentStep = 2; // Move to OTP verification
        } catch (\Exception $e) {
            $this->form->addError('phone', $e->getMessage());
        }
    }

    public function verifyOtp(CustomerOtpService $otpService)
    {
        \Illuminate\Support\Facades\Log::info("verifyOtp called with OTP: " . $this->form->otp);
        $this->form->validateOnly('otp');

        try {
            // [TESTING BYPASS] - Allow '000000' for local testing
            if ($this->form->otp === '000000' && app()->environment('local', 'testing')) {
                \Illuminate\Support\Facades\Log::info("Using OTP bypass");
                $customer = \App\Models\Customer::firstOrCreate(
                    ['phone' => $this->form->phone, 'tenant_id' => $this->tenant->id],
                    ['name' => 'زائر تجريبي']
                );
            } else {
                \Illuminate\Support\Facades\Log::info("Using real OTP verification");
                // This service method must now handle logging in the customer upon success
                $customer = $otpService->verifyOtp($this->tenant, $this->form->phone, $this->form->otp);
            }
            
            \Illuminate\Support\Facades\Log::info("Logging in customer: " . $customer->id);
            // Explicitly log the customer in using the customer guard
            Auth::guard('customer')->login($customer);
            
            $this->currentStep = 3; // Move to Passenger details
            \Illuminate\Support\Facades\Log::info("Step is now 3");
            
        } catch (OtpCoolDownException | InvalidOtpException $e) {
            \Illuminate\Support\Facades\Log::error("OTP verification error: " . $e->getMessage());
            $this->form->addError('otp', $e->getMessage());
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("OTP unexpected error: " . $e->getMessage());
            $this->form->addError('otp', 'An unexpected error occurred. Please try again.');
        }
    }

    public function submitPassengers()
    {
        // Must be logged in to proceed
        if (!Auth::guard('customer')->check()) {
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
        // 1. Strict Security: Must be logged in
        if (!Auth::guard('customer')->check()) {
            $this->currentStep = 1;
            return;
        }

        $customer = Auth::guard('customer')->user();

        // 2. Critical Cross-Tenant Check
        if ($customer->tenant_id !== $this->tripInstance->tenant_id) {
            throw new UnauthorizedException("Customer does not belong to this tenant.");
        }

        // 3. Final Form Validation (Ensures tiers/addons belong to this trip)
        $this->form->validate();

        try {
            // Compile Unified Payload Array (DTO format)
            $payload = [
                'tenant_id' => $this->tenant->id,
                'trip_instance_id' => $this->tripInstance->id,
                'customer_id' => Auth::guard('customer')->id(),
                'user_id' => null, // Not an admin
                'passengersData' => $this->form->passengers,
                'addonsData' => $this->form->addons,
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
