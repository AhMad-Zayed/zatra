<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\Customer;
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
            $customer = Customer::create([
                'tenant_id' => $tenant->id,
                'email' => $socialUser->getEmail(),
                'name' => $socialUser->getName() ?? 'عميل',
                'provider_id' => $socialUser->getId(),
                'provider_name' => $provider,
                // Phone is nullable in the updated migration
            ]);
        } else {
            // Link social provider to existing account if not linked
            if ($customer->provider_id === null) {
                $customer->update([
                    'provider_id' => $socialUser->getId(),
                    'provider_name' => $provider,
                ]);
            }
        }

        Auth::guard('customer')->login($customer);

        // Strict Phone Check
        if (empty($customer->phone)) {
            return redirect()->route('portal.complete_profile', ['tenant' => $tenant->slug]);
        }

        return redirect()->route('storefront.catalog', ['tenant' => $tenant->slug]);
    }
}
