<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\TripTemplate;
use App\Models\TripInstance;
use App\Models\Booking;
use App\Enums\BookingStatus;
use App\Services\CustomerAuthService;
use App\Services\BookingService;
use App\Livewire\Storefront\Checkout;
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

    private CustomerAuthService $authService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->authService = new CustomerAuthService();
    }

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

    public function test_otp_flow_methods_in_auth_service(): void
    {
        Notification::fake();
        
        $phone = '+970599111222';
        $this->authService->sendOtp($phone);

        // Assert OTP is saved in cache
        $cachedOtp = Cache::get("otp:{$phone}");
        $this->assertNotEmpty($cachedOtp);
        $this->assertEquals(4, strlen($cachedOtp));

        // Verify correct OTP
        $this->assertTrue($this->authService->verifyOtp($phone, $cachedOtp));
        
        // Verify code is cleared after successful check
        $this->assertNull(Cache::get("otp:{$phone}"));

        // Verify wrong OTP fails
        $this->assertFalse($this->authService->verifyOtp($phone, '0000'));
    }

    public function test_one_page_checkout_livewire_component(): void
    {
        Notification::fake();
        
        $tenant = Tenant::create(['name' => 'Agency North']);
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

        // Bind the tenant to context
        app()->instance(Tenant::class, $tenant);

        Livewire::test(Checkout::class, ['instance' => $instance])
            ->set('phone', '0791234567')
            ->set('name', 'Hassan Booker')
            ->set('email', 'hassan@example.com')
            ->call('sendVerificationCode')
            ->assertSet('showOtpInput', true)
            ->set('otp', '1234') // 1234 works in testing env
            ->call('verifyCode')
            ->assertSet('isVerified', true)
            ->assertSet('showOtpInput', false)
            // Configure passengers
            ->set('passengerCount', 2)
            ->set('passengers.0.name', 'Passenger One')
            ->set('passengers.0.passport_number', 'P987654')
            ->set('passengers.1.name', 'Passenger Two')
            ->set('passengers.1.passport_number', 'P987653')
            // Set travel extras
            ->set('needsFlight', true)
            ->set('flightDetails', 'Direct flight requested')
            ->set('needsHotel', true)
            ->set('hotelDetails', 'Double room')
            ->call('submitBooking')
            ->assertHasNoErrors();

        // Assert booking details in DB
        $this->assertDatabaseHas('bookings', [
            'tenant_id' => $tenant->id,
            'status' => BookingStatus::PENDING->value,
            'total_amount' => 200.00, // 100 base price * 2 passengers
            'flight_details' => 'Direct flight requested',
            'hotel_details' => 'Double room',
        ]);

        // Assert passengers created
        $this->assertDatabaseHas('passengers', [
            'name' => 'Passenger One',
            'passport_number' => 'P987654',
        ]);
        $this->assertDatabaseHas('passengers', [
            'name' => 'Passenger Two',
            'passport_number' => 'P987653',
        ]);
    }

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

        $normalizedPhone = $this->authService->normalizePhone('0791234567');
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
