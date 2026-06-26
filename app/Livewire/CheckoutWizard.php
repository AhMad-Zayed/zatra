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

#[Layout('components.layouts.storefront')]
class CheckoutWizard extends Component
{
    public TripInstance $tripInstance;
    public Tenant $tenant;
    
    public BookingForm $form;
    
    public int $currentStep = 1;

    public function mount(Tenant $tenant, TripInstance $tripInstance)
    {
        $this->tenant = $tenant;
        $this->tripInstance = $tripInstance;
        
        // Pass the trip instance ID to the form for strict validation scoping
        $this->form->setTripInstanceId($tripInstance->id);
        
        // Add one default passenger row
        $this->form->addPassenger();
    }

    public function submitPhone(CustomerOtpService $otpService)
    {
        $this->form->validateOnly('phone');
        
        try {
            $otpService->sendOtp($this->form->phone, $this->tenant->id);
            $this->currentStep = 2; // Move to OTP verification
        } catch (\Exception $e) {
            $this->form->addError('phone', $e->getMessage());
        }
    }

    public function verifyOtp(CustomerOtpService $otpService)
    {
        $this->form->validateOnly('otp');

        try {
            // This service method must now handle logging in the customer upon success
            $customer = $otpService->verifyOtp($this->form->phone, $this->form->otp, $this->tenant->id);
            
            // Explicitly log the customer in using the customer guard
            Auth::guard('customer')->login($customer);
            
            $this->currentStep = 3; // Move to Passenger details
            
        } catch (OtpCoolDownException | InvalidOtpException $e) {
            $this->form->addError('otp', $e->getMessage());
        } catch (\Exception $e) {
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
        $this->form->validateOnly('passengers.*.trip_pricing_tier_id');

        $this->currentStep = 4; // Move to Addons (or final submit)
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
                'tenant_id' => $this->tripInstance->tenant_id,
                'trip_instance_id' => $this->tripInstance->id,
                'customer_id' => $customer->id,
                'passengersData' => $this->form->passengers,
                'addonsData' => $this->form->addons,
                'notes' => null,
                'user_id' => null, // Null because it's a B2C self-checkout (No Admin involved)
            ];

            // Call the refactored Service
            $booking = $bookingService->execute($payload);

            // Redirect to a success/payment page
            return redirect()->route('storefront.catalog', ['tenant' => $this->tenant->slug])
                             ->with('success', 'Booking created successfully!');

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
        return view('livewire.checkout-wizard');
    }
}
