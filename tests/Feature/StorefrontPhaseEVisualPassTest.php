<?php

namespace Tests\Feature;

use App\Livewire\Auth\CustomerLogin;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\TripInstance;
use App\Models\TripPassengerCategory;
use App\Models\TripTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regression coverage for the storefront redesign's Phase E (login + my-bookings visual pass).
 * Both screens had been missed by Phase A's flattening sweep: the login card still carried the
 * pre-redesign glassmorphism treatment (bg-white/70 backdrop-blur-xl, shadow-2xl) and the
 * my-bookings booking cards still carried the equivalent (bg-white/80 backdrop-blur-xl, shadow-xl,
 * hover:shadow-2xl), plus redundant colored drop-shadows on primary CTAs -- the same pattern
 * already flattened everywhere else in Phases A/D.
 *
 * Also fixes a real, separate bug found while touching this file: the login screen's "الدخول عبر
 * Google" button had no wire:click, no href, and no backing OAuth integration anywhere in
 * CustomerLogin.php -- it silently did nothing on click. Disabled it with the same "قريباً"
 * convention already used for the electronic payment method at checkout Step 4, rather than
 * leaving a clickable-but-broken control live.
 */
class StorefrontPhaseEVisualPassTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenant(): Tenant
    {
        return Tenant::create(['name' => 'Agency PhaseE', 'slug' => 'agency-phasee']);
    }

    public function test_login_screen_has_no_glassmorphism_or_heavy_shadow_remnants(): void
    {
        $tenant = $this->makeTenant();

        $html = Livewire::test(CustomerLogin::class, ['tenant' => $tenant])->html();

        $this->assertStringNotContainsString('backdrop-blur', $html);
        $this->assertStringNotContainsString('shadow-2xl', $html);
        $this->assertStringNotContainsString('shadow-lg', $html);
    }

    public function test_login_screen_disables_the_non_functional_google_button_with_coming_soon_badge(): void
    {
        $tenant = $this->makeTenant();

        $html = Livewire::test(CustomerLogin::class, ['tenant' => $tenant])->html();

        $this->assertStringContainsString('قريباً', $html);
        $this->assertStringContainsString('الدخول عبر Google', $html);

        // The Google button itself (search backwards from the label to its opening tag) must be
        // disabled -- it has no working OAuth integration behind it.
        $openTagPos = strrpos(substr($html, 0, strpos($html, 'الدخول عبر Google')), '<button');
        $openTag = substr($html, $openTagPos, strpos($html, '>', $openTagPos) - $openTagPos);
        $this->assertStringContainsString('disabled', $openTag);
    }

    public function test_phone_otp_login_still_works_end_to_end_after_the_visual_pass(): void
    {
        $tenant = $this->makeTenant();

        $component = Livewire::test(CustomerLogin::class, ['tenant' => $tenant])
            ->set('identifier', '0599111222')
            ->call('sendOtp')
            ->assertSet('step', 2)
            ->set('otp', '1234')
            ->call('verifyOtp');

        $component->assertRedirect(route('storefront.my-bookings', ['tenant' => $tenant->slug]));
        $this->assertTrue(auth('customer')->check());
        $this->assertEquals('0599111222', auth('customer')->user()->phone);
    }

    public function test_my_bookings_card_has_no_glassmorphism_or_heavy_shadow_remnants(): void
    {
        $tenant = $this->makeTenant();
        $template = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'Phase E Trip', 'base_price' => 0, 'is_active' => true]);
        $instance = TripInstance::create([
            'tenant_id' => $tenant->id, 'trip_template_id' => $template->id,
            'start_date' => now()->addDays(20), 'end_date' => now()->addDays(25),
            'available_seats' => 20, 'status' => 'active',
        ]);
        $category = TripPassengerCategory::create([
            'tenant_id' => $tenant->id, 'trip_instance_id' => $instance->id,
            'name' => 'Adult', 'price' => 5000, 'requires_seat' => true,
        ]);
        $customer = Customer::create(['tenant_id' => $tenant->id, 'name' => 'Test Customer', 'phone' => '0599111222']);
        $booking = Booking::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'trip_instance_id' => $instance->id,
            'pnr' => 'ZTR-TESTE01',
            'currency' => 'USD',
            'booking_status' => \App\Enums\BookingStatus::Confirmed,
            'payment_status' => \App\Enums\PaymentStatus::Unpaid,
            'payment_type' => \App\Enums\PaymentType::FULL,
            'grand_total' => 500000,
            'total_paid' => 0,
            'balance_due' => 500000,
            'snapshot_trip_title' => $template->title,
            'snapshot_start_date' => $instance->start_date,
            'snapshot_end_date' => $instance->end_date,
        ]);
        \App\Models\Passenger::create([
            'tenant_id' => $tenant->id, 'booking_id' => $booking->id,
            'trip_passenger_category_id' => $category->id,
            'price_at_booking' => 5000,
            'first_name' => 'Ahmad', 'last_name' => 'Test',
            'data_complete' => true, 'requirements_complete' => true,
        ]);

        $this->actingAs($customer, 'customer');

        $html = Livewire::test(\App\Livewire\Storefront\MyBookings::class, ['tenant' => $tenant])->html();

        $this->assertStringContainsString('ZTR-TESTE01', $html);
        $this->assertStringNotContainsString('backdrop-blur', $html);
        $this->assertStringNotContainsString('shadow-xl', $html);
        $this->assertStringNotContainsString('shadow-2xl', $html);
        $this->assertStringNotContainsString('shadow-lg', $html);
    }
}
