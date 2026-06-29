<?php

namespace App\Livewire\Auth;

use Livewire\Component;
use App\Models\Tenant;
use App\Services\CustomerOtpService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;

#[Layout('components.layouts.storefront')]
class CustomerLogin extends Component
{
    public Tenant $tenant;
    public string $identifier = '';
    public string $otp = '';
    public int $step = 1;

    public function mount(Tenant $tenant)
    {
        $this->tenant = $tenant;
        
        if (Auth::guard('customer')->check()) {
            return redirect()->route('storefront.catalog', ['tenant' => $tenant->slug]);
        }
    }

    public function sendOtp(CustomerOtpService $otpService)
    {
        $this->validate([
            'identifier' => 'required|string|min:5',
        ]);

        try {
            $otpService->sendOtp($this->tenant, $this->identifier);
            $this->step = 2;
        } catch (\Exception $e) {
            $this->addError('identifier', $e->getMessage());
        }
    }

    public function verifyOtp(CustomerOtpService $otpService)
    {
        $this->validate([
            'otp' => 'required|string|min:4',
        ]);

        try {
            // Testing Bypass
            if ($this->otp === '1234' && app()->environment('local', 'testing')) {
                $customer = \App\Models\Customer::firstOrCreate(
                    ['phone' => $this->identifier, 'tenant_id' => $this->tenant->id],
                    ['name' => 'زائر تجريبي (VIP)']
                );
            } else {
                $customer = $otpService->verifyOtp($this->tenant, $this->identifier, $this->otp);
            }
            
            Auth::guard('customer')->login($customer);

            if (empty($customer->phone)) {
                return redirect()->route('portal.complete_profile', ['tenant' => $this->tenant->slug]);
            }

            return redirect()->route('storefront.my-bookings', ['tenant' => $this->tenant->slug]);

        } catch (\Exception $e) {
            $this->addError('otp', $e->getMessage());
        }
    }

    public function render()
    {
        return view('livewire.auth.customer-login');
    }
}
