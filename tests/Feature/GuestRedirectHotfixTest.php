<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * EMERGENCY FIX regression coverage: unlike CustomerBookingPortal (fixed by removing
 * auth:customer entirely -- that route never needed a login), storefront.my-bookings correctly
 * requires authentication. But this app has no route literally named "login", and Laravel's
 * default guest-redirect closure (route('login')) is unconditional -- so an unauthenticated
 * visitor hit a hard 500 (RouteNotFoundException: Route [login] not defined) instead of a clean
 * redirect to the real login page. Confirmed live via a fresh unauthenticated request before this
 * fix.
 *
 * Fixed in bootstrap/app.php via Middleware::redirectGuestsTo(), which Laravel invokes with the
 * failing $request -- resolved to the correct tenant-scoped portal.login for any {tenant:slug}
 * route, falling back to '/' for anything else (e.g. the plain 'auth'-protected staff download
 * routes) rather than crashing.
 */
class GuestRedirectHotfixTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unauthenticated_visitor_to_my_bookings_gets_redirected_to_login_not_a_500(): void
    {
        $tenant = Tenant::create(['name' => 'Agency Guest Redirect', 'slug' => 'agency-guest-redirect']);

        $response = $this->get(route('storefront.my-bookings', ['tenant' => $tenant->slug]));

        $response->assertRedirect(route('portal.login', ['tenant' => $tenant->slug]));
    }

    public function test_the_redirected_to_login_page_actually_loads(): void
    {
        $tenant = Tenant::create(['name' => 'Agency Guest Redirect Two', 'slug' => 'agency-guest-redirect-two']);

        $response = $this->get(route('storefront.my-bookings', ['tenant' => $tenant->slug]));
        $response->assertRedirect(route('portal.login', ['tenant' => $tenant->slug]));

        $loginPage = $this->get($response->headers->get('Location'));
        $loginPage->assertOk();
    }

    public function test_an_authenticated_customer_still_reaches_my_bookings_directly(): void
    {
        $tenant = Tenant::create(['name' => 'Agency Guest Redirect Three', 'slug' => 'agency-guest-redirect-three']);
        $customer = \App\Models\Customer::create(['name' => 'Jane', 'phone' => '0598877001', 'tenant_id' => $tenant->id]);

        $response = $this->actingAs($customer, 'customer')
            ->get(route('storefront.my-bookings', ['tenant' => $tenant->slug]));

        $response->assertOk();
    }

    public function test_guest_redirect_for_a_non_tenant_scoped_auth_route_falls_back_to_home_instead_of_crashing(): void
    {
        // The plain 'auth'-protected admin routes (secure-media, manifest, rooming-list) carry no
        // {tenant:slug} route param -- confirms the fallback branch of the redirect closure
        // (return '/') is reached cleanly rather than crashing, for parity with the tenant-scoped
        // case above.
        $response = $this->get('/admin/secure-media/999999');

        $response->assertRedirect('/');
    }
}
