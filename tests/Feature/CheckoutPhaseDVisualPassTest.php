<?php

namespace Tests\Feature;

use App\Livewire\CheckoutWizard;
use App\Models\Tenant;
use App\Models\TripAddon;
use App\Models\TripInstance;
use App\Models\TripPassengerCategory;
use App\Models\TripTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regression coverage for the storefront redesign's Phase D (rest of checkout: lead capture,
 * rooms/add-ons visual pass, payment step, booking success). Phase D was a pure styling pass --
 * flattening the Step 3 addon-selection card (dropping the glassmorphism treatment used by earlier
 * screens, in line with Phase A's near-flat direction) and removing redundant colored drop-shadows
 * from the primary buttons on the payment step and the booking-success page (bg-zatara-blue and
 * WhatsApp buttons) -- none of it touched CreateBookingService, form validation, or pricing logic.
 * This test asserts the styling landed and that toggling an addon through to a completed booking
 * still works exactly as before.
 */
class CheckoutPhaseDVisualPassTest extends TestCase
{
    use RefreshDatabase;

    private function makeFixtureWithAddon(): array
    {
        $tenant = Tenant::create(['name' => 'Agency PhaseD', 'slug' => 'agency-phased']);
        $template = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'Phase D Trip', 'base_price' => 0, 'is_active' => true]);
        $instance = TripInstance::create([
            'tenant_id' => $tenant->id, 'trip_template_id' => $template->id,
            'start_date' => now()->addDays(20), 'end_date' => now()->addDays(25),
            'available_seats' => 20, 'status' => 'active',
        ]);
        $adult = TripPassengerCategory::create([
            'tenant_id' => $tenant->id, 'trip_instance_id' => $instance->id,
            'name' => 'Adult', 'price' => 5000, 'requires_seat' => true,
        ]);
        $addon = TripAddon::create([
            'tenant_id' => $tenant->id, 'trip_instance_id' => $instance->id,
            'name' => 'Airport Transfer', 'price' => 300, 'max_quantity' => 1,
        ]);

        return compact('tenant', 'instance', 'adult', 'addon');
    }

    public function test_the_addon_selection_card_uses_the_flattened_near_flat_treatment(): void
    {
        $f = $this->makeFixtureWithAddon();

        $html = Livewire::test(CheckoutWizard::class, ['tenant' => $f['tenant'], 'tripInstance' => $f['instance']])
            ->set('form.passengers.0.first_name', 'Test')
            ->set('form.passengers.0.last_name', 'Passenger')
            ->set('form.email', 'phased1@example.com')
            ->call('submitLeadCapture')
            ->set('form.passengers.0.trip_passenger_category_id', $f['adult']->id)
            ->call('submitPassengers')
            ->html();

        $this->assertStringContainsString('toggleAddon(' . $f['addon']->id . ')', $html);

        $cardMarkup = substr($html, (int) strpos($html, 'toggleAddon(' . $f['addon']->id . ')'));
        $cardMarkup = substr($cardMarkup, 0, 400);

        $this->assertStringNotContainsString('shadow-lg', $cardMarkup);
        $this->assertStringNotContainsString('backdrop-blur', $cardMarkup);
    }

    public function test_toggling_an_addon_and_completing_the_booking_still_works_after_the_visual_pass(): void
    {
        $f = $this->makeFixtureWithAddon();

        $component = Livewire::test(CheckoutWizard::class, ['tenant' => $f['tenant'], 'tripInstance' => $f['instance']])
            ->set('form.passengers.0.first_name', 'Test')
            ->set('form.passengers.0.last_name', 'Passenger')
            ->set('form.email', 'phased2@example.com')
            ->call('submitLeadCapture')
            ->set('form.passengers.0.trip_passenger_category_id', $f['adult']->id)
            ->call('submitPassengers')
            ->call('toggleAddon', $f['addon']->id)
            ->assertSet('addonsSubtotal', 300.0)
            ->call('submitAddons')
            ->set('paymentMethod', 'cash')
            ->set('paymentType', 'full')
            ->call('submitBooking');

        $bookingId = $component->get('booking_id');
        $this->assertNotNull($bookingId);

        // NOTE: this only asserts the addon selection itself reaches CreateBookingService intact
        // (a BookingAddon row with the right addon/quantity/price snapshot) -- not that it survives
        // into the booking's final grand_total. It doesn't: BookingService::recalculateTotals(),
        // called unconditionally at the end of every booking creation, reads $booking->addons,
        // but Booking only defines a bookingAddons() relation, not addons() -- so that line
        // silently evaluates to null/[] and the addon charge is dropped from grand_total on every
        // booking that has one, regardless of storefront/checkout styling. Confirmed pre-existing,
        // unrelated to Phase D's visual pass, and out of scope here since BookingService is on the
        // standing zero-changes guardrail list -- flagged to the user separately instead of fixed.
        $bookingAddon = \App\Models\BookingAddon::where('booking_id', $bookingId)->first();
        $this->assertNotNull($bookingAddon);
        $this->assertEquals($f['addon']->id, $bookingAddon->trip_addon_id);
        $this->assertEquals(1, $bookingAddon->quantity);
        $this->assertEquals(300.0, (float) $bookingAddon->price_at_booking);
    }

    public function test_booking_success_action_buttons_have_no_redundant_drop_shadow(): void
    {
        $view = file_get_contents(resource_path('views/livewire/booking-success.blade.php'));

        $this->assertStringNotContainsString('shadow-lg shadow-zatara-blue/20', $view);
        $this->assertStringNotContainsString('shadow-lg shadow-[#25D366]/20', $view);
    }
}
