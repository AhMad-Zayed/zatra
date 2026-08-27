<?php

namespace Tests\Feature;

use App\Livewire\CheckoutWizard;
use App\Livewire\StorefrontCatalog;
use App\Livewire\Storefront\MyBookings;
use App\Livewire\TripDetails;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\TripInstance;
use App\Models\TripPassengerCategory;
use App\Models\TripTemplate;
use App\Services\CreateBookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regression coverage for a live-reproduced storefront bug (docs/STOREFRONT_UX_AUDIT.md,
 * Friction Point #4): several storefront screens hardcoded a currency literal ("دولار"/"$" for
 * passengers/totals, "SAR"/"ريال" for add-ons and the booking portal) regardless of the trip's or
 * booking's actual configured currency. Live-confirmed on My Bookings: a real $15,090 USD booking
 * displayed as "SAR 15,090.00". Every price now reads the trip's/booking's real `currency` field.
 *
 * The fixture below deliberately prices the trip in JOD (not USD) -- a stale hardcoded "$"/"دولار"
 * literal would silently pass a USD-only test, so JOD is used everywhere to make sure the real
 * currency field is actually being read.
 */
class StorefrontCurrencyDisplayTest extends TestCase
{
    use RefreshDatabase;

    private function makeFixture(): array
    {
        $tenant = Tenant::create(['name' => 'Agency Currency', 'slug' => 'agency-currency']);

        $template = TripTemplate::create([
            'tenant_id' => $tenant->id,
            'title' => 'Petra Desert Trip',
            'currency' => 'JOD',
            'base_price' => 0,
            'is_active' => true,
        ]);
        $instance = TripInstance::create([
            'tenant_id' => $tenant->id,
            'trip_template_id' => $template->id,
            'currency' => 'JOD',
            'start_date' => now()->addDays(30),
            'end_date' => now()->addDays(35),
            'available_seats' => 20,
            'status' => 'active',
        ]);
        $category = TripPassengerCategory::create([
            'tenant_id' => $tenant->id,
            'trip_instance_id' => $instance->id,
            'name' => 'Adult',
            'price' => 300,
            'requires_seat' => true,
        ]);

        return compact('tenant', 'template', 'instance', 'category');
    }

    public function test_catalog_card_shows_the_trips_real_currency(): void
    {
        $f = $this->makeFixture();

        Livewire::test(StorefrontCatalog::class, ['tenant' => $f['tenant']])
            ->assertSee('JOD')
            ->assertDontSee('دولار')
            ->assertDontSee('SAR');
    }

    public function test_trip_details_shows_the_trips_real_currency_everywhere(): void
    {
        $f = $this->makeFixture();

        Livewire::test(TripDetails::class, ['tenant' => $f['tenant'], 'tripTemplate' => $f['template']])
            ->assertSee('JOD')
            ->assertDontSee('دولار')
            ->assertDontSee('SAR');
    }

    public function test_checkout_wizard_category_and_summary_show_the_trips_real_currency(): void
    {
        $f = $this->makeFixture();

        $component = Livewire::test(CheckoutWizard::class, ['tenant' => $f['tenant'], 'tripInstance' => $f['instance']]);
        $component->assertSee('JOD')->assertDontSee('دولار');

        $component->set('form.passengers.0.first_name', 'Test')
            ->set('form.passengers.0.last_name', 'Passenger')
            ->set('form.email', 'currency@example.com')
            ->call('submitLeadCapture')
            ->set('form.passengers.0.trip_passenger_category_id', $f['category']->id)
            ->call('submitPassengers')
            ->call('submitAddons')
            ->assertSee('JOD')
            ->assertDontSee('SAR');
    }

    public function test_my_bookings_shows_the_bookings_real_currency_not_a_hardcoded_one(): void
    {
        $f = $this->makeFixture();
        $customer = Customer::create(['tenant_id' => $f['tenant']->id, 'name' => 'Test Customer', 'phone' => '+962790000000']);

        $booking = app(CreateBookingService::class)->execute([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'customer_id' => $customer->id,
            'passengersData' => [
                ['trip_passenger_category_id' => $f['category']->id, 'first_name' => 'Test', 'last_name' => 'Passenger'],
            ],
        ]);
        $this->assertEquals('JOD', $booking->fresh()->currency);

        Auth::guard('customer')->login($customer);

        Livewire::test(MyBookings::class, ['tenant' => $f['tenant']])
            ->assertSee('JOD')
            ->assertDontSee('SAR');
    }

    // Note: App\Livewire\CustomerPortal (resources/views/livewire/customer-portal.blade.php,
    // where two hardcoded "ريال" literals were also fixed alongside this ticket's other currency
    // fixes) is not reachable by any route -- portal.dashboard redirects straight to
    // Storefront\MyBookings (covered above), and no other route renders CustomerPortal. It also
    // has a pre-existing, unrelated crash (references a nonexistent TripInstance::template()
    // relation instead of tripTemplate()) that is out of scope here since dead code can't affect
    // a real customer. No regression test is written against it for the same reason.
}
