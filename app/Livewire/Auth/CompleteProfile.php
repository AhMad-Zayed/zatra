<?php

namespace App\Livewire\Auth;

use Livewire\Component;
use App\Models\Tenant;
use App\Models\Customer;
use App\Services\CustomerOtpService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;

#[Layout('components.layouts.storefront')]
class CompleteProfile extends Component
{
    public Tenant $tenant;
    public string $phone = '';
    public string $otp = '';
    public int $step = 1;

    public function mount(Tenant $tenant)
    {
        $this->tenant = $tenant;
        
        $customer = Auth::guard('customer')->user();
        if (!$customer || $customer->phone) {
            return redirect()->route('storefront.catalog', ['tenant' => $tenant->slug]);
        }
    }

    public function sendOtp(CustomerOtpService $otpService)
    {
        $this->validate([
            'phone' => 'required|string|min:7',
        ]);

        try {
            $otpService->sendOtp($this->tenant, $this->phone);
            $this->step = 2;
        } catch (\Exception $e) {
            $this->addError('phone', $e->getMessage());
        }
    }

    public function verifyOtp(CustomerOtpService $otpService)
    {
        $this->validate([
            'otp' => 'required|string|min:4',
        ]);

        try {
            // This verifies the OTP and returns the Customer record associated with the phone
            $verifiedCustomer = $otpService->verifyOtp($this->tenant, $this->phone, $this->otp);
            
            $currentSocialUser = Auth::guard('customer')->user();

            // SMART MERGING LOGIC
            if ($currentSocialUser->id !== $verifiedCustomer->id) {
                // The phone either belonged to an existing account, or a new one was just created by sendOtp
                
                // Merge data into the verified phone account (The Anchor)
                $verifiedCustomer->update([
                    'email' => $verifiedCustomer->email ?? $currentSocialUser->email,
                    'provider_id' => $verifiedCustomer->provider_id ?? $currentSocialUser->provider_id,
                    'provider_name' => $verifiedCustomer->provider_name ?? $currentSocialUser->provider_name,
                    'name' => $verifiedCustomer->name !== 'زائر تجريبي' ? $verifiedCustomer->name : ($currentSocialUser->name ?? 'عميل'),
                ]);

                // Re-assign any bookings from the temporary social account to the anchor account
                \App\Models\Booking::where('customer_id', $currentSocialUser->id)
                    ->update(['customer_id' => $verifiedCustomer->id]);

                // Log into the Anchor account
                Auth::guard('customer')->login($verifiedCustomer);

                // Delete the temporary social-only account
                $currentSocialUser->delete();
            }

            return redirect()->route('storefront.catalog', ['tenant' => $this->tenant->slug]);

        } catch (\Exception $e) {
            $this->addError('otp', $e->getMessage());
        }
    }

    public function render()
    {
        return view('livewire.auth.complete-profile');
    }
}
