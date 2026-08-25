<?php

namespace App\Livewire\Auth;

use Livewire\Component;
use App\Models\Tenant;
use App\Models\Customer;
use App\Services\CustomerOtpService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;

/**
 * Confirms a pending social-provider account link (see
 * SocialAuthController::callback()). Reached only when an existing local account was
 * matched by email but the provider did not attest that it verified ownership of that
 * email - the requester must prove control of the existing account via an OTP sent to
 * its own email before the social identity is merged onto it and the session is logged
 * in. Nothing is persisted or authenticated until verifyOtp() succeeds.
 */
#[Layout('components.layouts.storefront')]
class ConfirmSocialLink extends Component
{
    public Tenant $tenant;
    public string $otp = '';
    public string $maskedEmail = '';

    public function mount(Tenant $tenant)
    {
        $this->tenant = $tenant;

        $pending = $this->validPendingLink();
        if (!$pending) {
            return redirect()->route('portal.login', ['tenant' => $tenant->slug])
                ->with('error', 'انتهت صلاحية طلب الربط. يرجى المحاولة مرة أخرى.');
        }

        $customer = Customer::find($pending['customer_id']);
        $this->maskedEmail = $customer
            ? \Illuminate\Support\Str::mask($customer->email, '*', 2, -8)
            : '';
    }

    private function validPendingLink(): ?array
    {
        $pending = session('pending_social_link');

        if (!$pending
            || ($pending['tenant_id'] ?? null) !== $this->tenant->id
            || ($pending['expires_at'] ?? 0) < now()->timestamp
        ) {
            session()->forget('pending_social_link');
            return null;
        }

        return $pending;
    }

    public function verifyOtp(CustomerOtpService $otpService)
    {
        $this->validate(['otp' => 'required|string|min:4']);

        $pending = $this->validPendingLink();
        if (!$pending) {
            $this->addError('otp', 'انتهت صلاحية الطلب. يرجى المحاولة مرة أخرى.');
            return;
        }

        $customer = Customer::where('id', $pending['customer_id'])
            ->where('tenant_id', $this->tenant->id)
            ->first();

        if (!$customer) {
            session()->forget('pending_social_link');
            $this->addError('otp', 'تعذر العثور على الحساب.');
            return;
        }

        try {
            $verifiedCustomer = $otpService->verifyOtp($this->tenant, $customer->email, $this->otp);
        } catch (\Exception $e) {
            $this->addError('otp', $e->getMessage());
            return;
        }

        // Defense in depth: the OTP must have been verified against the exact account
        // this link is pending for, not merely some other account under the same tenant.
        if ($verifiedCustomer->id !== $customer->id) {
            $this->addError('otp', 'حدث خطأ أثناء التحقق.');
            return;
        }

        $customer->update([
            'provider_id' => $pending['provider_id'],
            'provider_name' => $pending['provider'],
        ]);

        session()->forget('pending_social_link');
        Auth::guard('customer')->login($customer);

        if (empty($customer->phone)) {
            return redirect()->route('portal.complete_profile', ['tenant' => $this->tenant->slug]);
        }

        return redirect()->route('storefront.catalog', ['tenant' => $this->tenant->slug]);
    }

    public function cancel()
    {
        session()->forget('pending_social_link');
        return redirect()->route('portal.login', ['tenant' => $this->tenant->slug]);
    }

    public function render()
    {
        return view('livewire.auth.confirm-social-link');
    }
}
