<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\Customer;
use App\Services\CustomerOtpService;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Http\Request;

class SocialAuthController extends Controller
{
    public function redirect(Tenant $tenant, $provider)
    {
        // Dynamically set the callback URL to include the tenant slug
        config(['services.'.$provider.'.redirect' => route('social.callback', ['tenant' => $tenant->slug, 'provider' => $provider])]);
        return Socialite::driver($provider)->redirect();
    }

    public function callback(Tenant $tenant, $provider)
    {
        config(['services.'.$provider.'.redirect' => route('social.callback', ['tenant' => $tenant->slug, 'provider' => $provider])]);

        try {
            $socialUser = Socialite::driver($provider)->user();
        } catch (\Exception $e) {
            return redirect()->route('storefront.catalog', ['tenant' => $tenant->slug])->with('error', 'Authentication failed');
        }

        // Prevent duplicates by checking if email exists in THIS tenant
        $customer = Customer::where('tenant_id', $tenant->id)
                    ->where('email', $socialUser->getEmail())
                    ->first();

        if ($customer && $customer->provider_id !== null && $customer->provider_id !== $socialUser->getId()) {
            return redirect()->route('storefront.catalog', ['tenant' => $tenant->slug])
                             ->with('error', 'Email already in use with a different provider.');
        }

        if (!$customer) {
            // No existing local account for this email - nothing to take over. Safe to
            // create and link immediately.
            $customer = Customer::create([
                'tenant_id' => $tenant->id,
                'email' => $socialUser->getEmail(),
                'name' => $socialUser->getName() ?? 'عميل',
                'provider_id' => $socialUser->getId(),
                'provider_name' => $provider,
                // Phone is nullable in the updated migration
            ]);

            return $this->loginAndRedirect($customer, $tenant);
        }

        if ($customer->provider_id !== null) {
            // Already linked to this exact provider identity - an ordinary repeat login.
            return $this->loginAndRedirect($customer, $tenant);
        }

        // An existing account was found by email, and it is not yet linked to any social
        // provider. Auto-linking here on email match alone is an account-takeover vector:
        // an attacker who controls a social-provider account using the victim's email
        // string (but not the victim's inbox) would otherwise be logged straight into the
        // victim's Zatara account. Only skip the extra check when the provider itself
        // explicitly attests that it verified ownership of the email address.
        if ($this->providerConfirmsVerifiedEmail($socialUser)) {
            $customer->update([
                'provider_id' => $socialUser->getId(),
                'provider_name' => $provider,
            ]);

            return $this->loginAndRedirect($customer, $tenant);
        }

        // Provider does not attest to email ownership - do NOT link or log in yet. Require
        // the requester to prove control of the existing account by verifying an OTP sent
        // to that account's own email address before the identities are merged.
        session(['pending_social_link' => [
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'provider' => $provider,
            'provider_id' => $socialUser->getId(),
            'expires_at' => now()->addMinutes(10)->timestamp,
        ]]);

        app(CustomerOtpService::class)->sendOtp($tenant, $customer->email);

        return redirect()->route('social.confirm-link', ['tenant' => $tenant->slug]);
    }

    private function loginAndRedirect(Customer $customer, Tenant $tenant)
    {
        Auth::guard('customer')->login($customer);

        // Strict Phone Check
        if (empty($customer->phone)) {
            return redirect()->route('portal.complete_profile', ['tenant' => $tenant->slug]);
        }

        return redirect()->route('storefront.catalog', ['tenant' => $tenant->slug]);
    }

    /**
     * Whether the OAuth provider itself attests that it verified ownership of the email
     * address it returned (e.g. Google's `email_verified`, Apple's `email_verified` ID
     * token claim). Both are exposed by Socialite via the raw provider payload on
     * $socialUser->user. Providers that don't supply this signal are treated as
     * unverified - the safest default, per the caller's OTP fallback.
     *
     * Untyped $socialUser: laravel/socialite is not currently a project dependency
     * (see composer.json), so its Contracts\User interface cannot be type-hinted here
     * without breaking autoloading/static analysis before the package is installed.
     */
    private function providerConfirmsVerifiedEmail($socialUser): bool
    {
        $raw = $socialUser->user ?? [];
        $verified = $raw['email_verified'] ?? $raw['verified_email'] ?? null;

        if (is_bool($verified)) {
            return $verified;
        }

        if (is_string($verified)) {
            return strtolower($verified) === 'true';
        }

        return false;
    }
}
