<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The header's account icon (resources/views/components/layouts/storefront.blade.php) was
 * wrapped in @auth('customer') with no @else branch at all -- a logged-out visitor, including a
 * returning customer with a real booking, had zero visible way to discover that a login/my-
 * bookings feature even existed anywhere on the storefront: no header icon, no footer link,
 * nothing. The mobile drawer had the identical gap in an even more basic form: it never
 * referenced login/my-bookings at all, in either auth state, not a separate gate on the same
 * link.
 *
 * Fixed by adding a real @else branch (desktop header icon-button, matching the existing
 * scrolled-reactive color pattern already used for the search icon right above it) and a new
 * drawer list item (mobile), both switching between "تسجيل الدخول" (logged out) and the existing
 * حجوزاتي/account icon (logged in, unchanged).
 *
 * These tests render the full page via a real HTTP request (not Livewire::test(), which only
 * renders the component's own view -- the header lives in the shared #[Layout(...)] wrapper, so
 * a real request is the only way to actually exercise it).
 */
class StorefrontHeaderAuthLinkTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenant(string $suffix): Tenant
    {
        return Tenant::create(['name' => "Agency {$suffix}", 'slug' => "agency-headerauth-{$suffix}"]);
    }

    public function test_a_logged_out_visitor_sees_a_real_login_link_in_the_header(): void
    {
        $tenant = $this->makeTenant('001');

        $response = $this->get(route('storefront.catalog', ['tenant' => $tenant->slug]));

        $response->assertOk();
        $response->assertSee('تسجيل الدخول');
        $response->assertSee(route('portal.login', ['tenant' => $tenant->slug]), false);
        // The old broken state: nothing at all in that spot for a logged-out visitor.
        $response->assertDontSee('حجوزاتي');
    }

    public function test_a_logged_out_visitor_sees_the_login_link_in_the_mobile_drawer_too(): void
    {
        $tenant = $this->makeTenant('002');

        // The mobile drawer had no login/account entry at all -- confirms the new drawer list
        // item is actually present in the response, not just the desktop header's copy.
        $response = $this->get(route('storefront.catalog', ['tenant' => $tenant->slug]));

        $html = $response->getContent();
        $this->assertSame(1, substr_count($html, 'title="تسجيل الدخول"'), 'Exactly one desktop header link with this title.');
        // Two total occurrences of the visible link text: the desktop header's <span> and the
        // mobile drawer's list item.
        $this->assertGreaterThanOrEqual(2, substr_count($html, 'تسجيل الدخول'));
    }

    public function test_a_logged_in_customer_sees_the_account_icon_instead_of_the_login_link(): void
    {
        $tenant = $this->makeTenant('003');
        $customer = Customer::create(['tenant_id' => $tenant->id, 'name' => 'Real Customer', 'phone' => '+966500000099']);

        $response = $this->actingAs($customer, 'customer')
            ->get(route('storefront.catalog', ['tenant' => $tenant->slug]));

        $response->assertOk();
        $response->assertSee('حجوزاتي');
        $response->assertSee(route('storefront.my-bookings', ['tenant' => $tenant->slug]), false);
        $response->assertDontSee('تسجيل الدخول');
    }
}
