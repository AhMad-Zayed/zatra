<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Tenant;
use App\Models\TripInstance;
use App\Models\TripPassengerCategory;
use App\Models\TripTemplate;
use App\Services\CreateBookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Customer Portal Consolidation: PortalController, CustomerAuthService, and
 * resources/views/portal/* have been retired -- Storefront\MyBookings and
 * CustomerBookingPortal (fixed in the preceding emergency hotfix) are now the sole
 * customer-facing "my bookings" surfaces. The old /t/{tenant_slug}/portal/dashboard and
 * /t/{tenant_slug}/portal/logout URLs are kept alive as permanent redirects (a safety net for
 * any un-discoverable old link) rather than turned into a hard 404.
 */
class CustomerBookingPortalConsolidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_portal_controller_and_its_service_no_longer_exist(): void
    {
        // $autoload = false: a true autoload attempt would try to require the deleted file via
        // composer's PSR-4 resolution and throw, rather than simply resolving to "not found".
        $this->assertFalse(class_exists('App\Http\Controllers\PortalController', false));
        $this->assertFalse(class_exists('App\Services\CustomerAuthService', false));
        $this->assertFalse(file_exists(app_path('Http/Controllers/PortalController.php')));
        $this->assertFalse(file_exists(app_path('Services/CustomerAuthService.php')));
    }

    public function test_legacy_portal_views_no_longer_exist(): void
    {
        $this->assertFalse(view()->exists('portal.login'));
        $this->assertFalse(view()->exists('portal.dashboard'));
    }

    public function test_old_dashboard_url_permanently_redirects_to_my_bookings(): void
    {
        $tenant = Tenant::create(['name' => 'Agency Redirect', 'slug' => 'agency-redirect']);

        // Old-style plain-string tenant_slug is resolved via Str::slug($tenant->name), exactly as
        // ResolveStorefrontTenant always has -- distinct from the tenant's real .slug column.
        $oldSlug = Str::slug($tenant->name);

        $response = $this->get("/t/{$oldSlug}/portal/dashboard");

        $response->assertStatus(301);
        $response->assertRedirect(route('storefront.my-bookings', ['tenant' => $tenant->slug]));
    }

    public function test_old_logout_url_permanently_redirects_to_login_and_logs_out(): void
    {
        $tenant = Tenant::create(['name' => 'Agency Logout', 'slug' => 'agency-logout']);
        $customer = Customer::create(['name' => 'Jane', 'phone' => '0599000111', 'tenant_id' => $tenant->id]);
        $oldSlug = Str::slug($tenant->name);

        $response = $this->actingAs($customer, 'customer')
            ->post("/t/{$oldSlug}/portal/logout");

        $response->assertStatus(301);
        $response->assertRedirect(route('portal.login', ['tenant' => $tenant->slug]));
        $this->assertFalse(Auth::guard('customer')->check());
    }

    public function test_old_dashboard_url_with_unknown_slug_still_404s(): void
    {
        $response = $this->get('/t/no-such-tenant/portal/dashboard');

        $response->assertNotFound();
    }

    /**
     * @return array{tenant: Tenant, customer: Customer, instance: TripInstance}
     */
    private function makeFixture(string $suffix): array
    {
        $tenant = Tenant::create([
            'name' => "Agency {$suffix}", 'slug' => "agency-cons-{$suffix}", 'domain' => "{$suffix}.zatara.com",
            'settings' => ['atlahub_account_id' => 'test', 'atlahub_inbox_id' => 'test', 'atlahub_api_token' => 'test'],
        ]);
        $customer = Customer::create(['name' => 'Jane', 'phone' => "0592{$suffix}", 'tenant_id' => $tenant->id]);
        $template = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'Tour', 'base_price' => 100]);
        $instance = TripInstance::create([
            'tenant_id' => $tenant->id,
            'trip_template_id' => $template->id,
            'start_date' => now()->addDays(5),
            'end_date' => now()->addDays(10),
            'available_seats' => 20,
            'status' => 'active',
        ]);

        return compact('tenant', 'customer', 'instance');
    }

    public function test_my_bookings_still_works_end_to_end_for_a_logged_in_customer(): void
    {
        $f = $this->makeFixture('101');
        $cat = TripPassengerCategory::create([
            'tenant_id' => $f['tenant']->id, 'trip_instance_id' => $f['instance']->id,
            'name' => 'Adult', 'price' => 100, 'requires_seat' => true,
        ]);
        $booking = (new CreateBookingService())->execute([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'customer_id' => $f['customer']->id,
            'passengersData' => [
                ['trip_passenger_category_id' => $cat->id, 'first_name' => 'P', 'last_name' => '1'],
            ],
        ]);

        $response = $this->actingAs($f['customer'], 'customer')
            ->get(route('storefront.my-bookings', ['tenant' => $f['tenant']->slug]));

        $response->assertOk();
        $response->assertSee($booking->pnr);
    }

    public function test_customer_login_otp_flow_still_reaches_my_bookings(): void
    {
        // The live OTP path (CustomerOtpService, via the CustomerLogin Livewire component) is
        // unaffected by this consolidation -- confirms it still lands on the surviving
        // storefront.my-bookings page, not the retired portal.dashboard view.
        $f = $this->makeFixture('102');

        Livewire::test(\App\Livewire\Auth\CustomerLogin::class, ['tenant' => $f['tenant']])
            ->set('identifier', $f['customer']->phone)
            ->call('sendOtp')
            ->set('otp', '1234')
            ->call('verifyOtp')
            ->assertRedirect(route('storefront.my-bookings', ['tenant' => $f['tenant']->slug]));

        $this->assertTrue(Auth::guard('customer')->check());
    }

    public function test_magic_link_still_works_unauthenticated_after_consolidation(): void
    {
        // The exact scenario the preceding emergency hotfix fixed, re-confirmed here as part of
        // the consolidation to prove nothing in this ticket's routing changes touched it.
        $f = $this->makeFixture('103');
        $cat = TripPassengerCategory::create([
            'tenant_id' => $f['tenant']->id, 'trip_instance_id' => $f['instance']->id,
            'name' => 'Adult', 'price' => 100, 'requires_seat' => true,
        ]);
        $booking = (new CreateBookingService())->execute([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'customer_id' => $f['customer']->id,
            'passengersData' => [
                ['trip_passenger_category_id' => $cat->id, 'first_name' => 'P', 'last_name' => '1'],
            ],
        ]);

        $response = $this->get(route('customer.booking.portal', $booking->uuid));

        $response->assertOk();
        $response->assertSee($booking->pnr);
    }
}
