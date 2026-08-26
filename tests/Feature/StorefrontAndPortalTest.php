<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\TripTemplate;
use App\Models\TripInstance;
use App\Models\Booking;
use App\Enums\BookingStatus;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\TestCase;

class StorefrontAndPortalTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_resolution_middleware(): void
    {
        $tenant = Tenant::create(['name' => 'Agency North', 'domain' => 'agency-north.com']);

        // Visit root - should redirect to first tenant storefront
        $response = $this->get('/');
        $response->assertRedirect(route('storefront.home', ['tenant_slug' => 'agency-north']));

        // Visit storefront using slug
        $response = $this->get('/t/agency-north');
        $response->assertStatus(200);
        $response->assertSee('Agency North');

        // Visit storefront with invalid slug -> should return 404
        $response = $this->get('/t/invalid-tenant');
        $response->assertStatus(404);
    }

    // test_otp_flow_methods_in_auth_service removed: exercised App\Services\CustomerAuthService
    // directly, which has been retired as part of the Customer Portal Consolidation (superseded
    // by CustomerOtpService, the live OTP path used by the CustomerLogin Livewire component). The
    // class no longer exists to test.

    // test_one_page_checkout_livewire_component removed: it exercised App\Livewire\Storefront\
    // Checkout, a confirmed-dead component (no route or <livewire:> tag ever mounted it in
    // production — the live `storefront.checkout` route is bound to CheckoutWizard) which has
    // been deleted. CheckoutWizard's own live checkout path is covered separately in
    // tests/Feature/Livewire/CheckoutWizardTest.php.

    // Known-failing (pre-existing baseline failure, left in place per standing rule): exercises
    // /t/{tenant_slug}/portal/send-otp and /verify-otp, which were already dead before the
    // Customer Portal Consolidation (no route ever pointed at PortalController::sendOtp/verifyOtp
    // -- see CustomerBookingPortalConsolidationTest for what replaced this flow). portal.dashboard
    // is now a redirect to storefront.my-bookings rather than a page, so this test would need a
    // full rewrite, not a fix, to pass -- out of scope for this ticket.
    public function test_customer_portal_login_and_dashboard(): void
    {
        $tenant = Tenant::create(['name' => 'Agency North']);
        app()->instance(Tenant::class, $tenant);

        // 1. Hit OTP send endpoint
        $response = $this->postJson("/t/agency-north/portal/send-otp", [
            'phone' => '0791234567',
        ]);
        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        // CustomerAuthService::normalizePhone() has been retired; inlined here since this line is
        // unreachable anyway (the assertStatus(200) above already throws -- the route is 404).
        $normalizedPhone = '+970791234567';
        $this->assertNotEmpty(Cache::get("otp:{$normalizedPhone}"));

        // 2. Verify OTP code and check redirect
        $response = $this->postJson("/t/agency-north/portal/verify-otp", [
            'phone' => '0791234567',
            'otp' => '1234',
        ]);
        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'redirect_url' => route('portal.dashboard', ['tenant_slug' => 'agency-north']),
        ]);

        // Assert user session is set
        $this->assertTrue(Auth::check());
        $user = Auth::user();
        $this->assertEquals($normalizedPhone, $user->phone);

        // 3. Access dashboard page
        $template = TripTemplate::create([
            'tenant_id' => $tenant->id,
            'title' => 'Wadi Rum Adventure',
            'base_price' => 100.00,
        ]);
        $instance = TripInstance::create([
            'tenant_id' => $tenant->id,
            'trip_template_id' => $template->id,
            'start_date' => now()->addDays(5),
            'end_date' => now()->addDays(7),
            'available_seats' => 15,
            'status' => 'active',
        ]);

        // Create a booking for this user
        $booking = Booking::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'trip_instance_id' => $instance->id,
            'reference' => 'AGN-26-00001',
            'status' => BookingStatus::PENDING,
            'total_amount' => 100.00,
        ]);

        $response = $this->actingAs($user)->get("/t/agency-north/portal/dashboard");
        $response->assertStatus(200);
        $response->assertSee('AGN-26-00001');
        $response->assertSee('Wadi Rum Adventure');
    }
}
