<?php

namespace Tests\Feature;

use App\Livewire\CheckoutWizard;
use App\Models\Tenant;
use App\Models\TripInstance;
use App\Models\TripPassengerCategory;
use App\Models\TripTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regression coverage for the storefront redesign's Phase C (Phase 0's Section C, corrected):
 * the passenger-category selector already existed and was already wired to real
 * TripPassengerCategory data -- the actual gap was that it used plain wire:model (deferred sync),
 * so nothing visibly updated at Step 2 itself until Step 4. Fixed by switching the <select> to
 * wire:model.live and surfacing a running-total widget in Step 2 that reuses
 * passengersSubtotal exactly as it already existed (Part 1) -- a pure display-layer change with
 * no effect on what CreateBookingService actually receives.
 */
class CheckoutLiveCategoryTotalTest extends TestCase
{
    use RefreshDatabase;

    private function makeFixtureWithTwoCategories(): array
    {
        $tenant = Tenant::create(['name' => 'Agency LiveTotal', 'slug' => 'agency-livetotal']);
        $template = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'LiveTotal Trip', 'base_price' => 0, 'is_active' => true]);
        $instance = TripInstance::create([
            'tenant_id' => $tenant->id, 'trip_template_id' => $template->id,
            'start_date' => now()->addDays(20), 'end_date' => now()->addDays(25),
            'available_seats' => 20, 'status' => 'active',
        ]);
        $adult = TripPassengerCategory::create([
            'tenant_id' => $tenant->id, 'trip_instance_id' => $instance->id,
            'name' => 'Adult', 'price' => 5000, 'requires_seat' => true,
        ]);
        $child = TripPassengerCategory::create([
            'tenant_id' => $tenant->id, 'trip_instance_id' => $instance->id,
            'name' => 'Child', 'price' => 2500, 'requires_seat' => true,
        ]);

        return compact('tenant', 'instance', 'adult', 'child');
    }

    public function test_selecting_a_category_updates_the_visible_total_without_advancing_steps(): void
    {
        $f = $this->makeFixtureWithTwoCategories();

        Livewire::test(CheckoutWizard::class, ['tenant' => $f['tenant'], 'tripInstance' => $f['instance']])
            ->assertSet('currentStep', 1)
            ->set('form.passengers.0.first_name', 'Test')
            ->set('form.passengers.0.last_name', 'Passenger')
            ->set('form.email', 'livetotal1@example.com')
            ->call('submitLeadCapture')
            ->assertSet('currentStep', 2)
            ->assertSet('passengersSubtotal', 0.0)
            ->set('form.passengers.0.trip_passenger_category_id', $f['adult']->id)
            ->assertSet('currentStep', 2) // still on Step 2 -- selecting a category must not submit/advance
            ->assertSet('passengersSubtotal', 5000.0)
            ->assertSee('5,000');
    }

    public function test_mixed_categories_across_multiple_passengers_sum_correctly_live(): void
    {
        $f = $this->makeFixtureWithTwoCategories();

        Livewire::test(CheckoutWizard::class, ['tenant' => $f['tenant'], 'tripInstance' => $f['instance']])
            ->set('form.passengers.0.first_name', 'Adult')
            ->set('form.passengers.0.last_name', 'One')
            ->set('form.email', 'livetotal2@example.com')
            ->call('submitLeadCapture')
            ->set('form.passengers.0.trip_passenger_category_id', $f['adult']->id)
            ->assertSet('passengersSubtotal', 5000.0)
            ->call('addPassenger')
            ->set('form.passengers.1.first_name', 'Child')
            ->set('form.passengers.1.last_name', 'One')
            ->set('form.passengers.1.trip_passenger_category_id', $f['child']->id)
            ->assertSet('passengersSubtotal', 7500.0)
            ->assertSee('7,500')
            ->assertSet('currentStep', 2); // never advanced, purely reactive
    }

    public function test_the_running_total_widget_is_hidden_when_the_trip_has_no_passenger_categories(): void
    {
        $tenant = Tenant::create(['name' => 'Agency NoCat', 'slug' => 'agency-nocat']);
        $template = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'No Category Trip', 'base_price' => 500, 'is_active' => true]);
        $instance = TripInstance::create([
            'tenant_id' => $tenant->id, 'trip_template_id' => $template->id,
            'start_date' => now()->addDays(20), 'end_date' => now()->addDays(25),
            'available_seats' => 20, 'status' => 'active',
        ]);

        Livewire::test(CheckoutWizard::class, ['tenant' => $tenant, 'tripInstance' => $instance])
            ->set('form.passengers.0.first_name', 'Test')
            ->set('form.passengers.0.last_name', 'Passenger')
            ->set('form.email', 'livetotal3@example.com')
            ->call('submitLeadCapture')
            ->assertDontSee('الإجمالي حتى الآن');
    }

    public function test_the_completed_booking_still_receives_exactly_the_selected_categories_and_prices(): void
    {
        $f = $this->makeFixtureWithTwoCategories();

        $component = Livewire::test(CheckoutWizard::class, ['tenant' => $f['tenant'], 'tripInstance' => $f['instance']])
            ->set('form.passengers.0.first_name', 'Adult')
            ->set('form.passengers.0.last_name', 'Passenger')
            ->set('form.email', 'livetotal4@example.com')
            ->call('submitLeadCapture')
            ->set('form.passengers.0.trip_passenger_category_id', $f['adult']->id)
            ->call('addPassenger')
            ->set('form.passengers.1.first_name', 'Child')
            ->set('form.passengers.1.last_name', 'Passenger')
            ->set('form.passengers.1.trip_passenger_category_id', $f['child']->id)
            ->call('submitPassengers')
            ->call('submitAddons')
            ->set('paymentMethod', 'cash')
            ->set('paymentType', 'full')
            ->call('submitBooking');

        $bookingId = $component->get('booking_id');
        $this->assertNotNull($bookingId);

        $booking = \App\Models\Booking::with('passengers.tripPassengerCategory')->find($bookingId);
        $this->assertEquals(7500.0, (float) $booking->grand_total);
        $this->assertCount(2, $booking->passengers);
        $this->assertEqualsCanonicalizing(
            ['Adult', 'Child'],
            $booking->passengers->pluck('tripPassengerCategory.name')->all()
        );
    }
}
