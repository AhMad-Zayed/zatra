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
 * URGENT bug fix: the "عدد المسافرين" (traveler count) control on trip-details' sticky booking
 * widget was pure decoration -- styled to look clickable (cursor-pointer, hover border) but had
 * no @click/wire:click handler anywhere, so clicking "+" never incremented anything and the
 * hardcoded "1 بالغ" text never changed. Live-reproduced against a real trip before the fix
 * (Puppeteer, clicking the actual rendered button): the displayed count stayed "1 بالغ" no matter
 * how many times "+" was clicked.
 *
 * Fixed with a real Alpine-only stepper (resources/views/livewire/trip-details.blade.php) -- no
 * Livewire round-trip needed for a plain increment/decrement -- whose value now genuinely flows
 * into the booking flow via a ?travelers=N query param on the "بدء إجراءات الحجز" CTA link, read
 * by CheckoutWizard::mount() to pre-add that many passenger rows instead of always exactly one.
 * The click-and-see-the-number-change part of this fix (Alpine state) isn't something PHPUnit can
 * exercise -- that was live-verified directly in a real browser (see the ticket's report) -- so
 * this test covers the two things that are genuinely server-side and regression-testable: the
 * blade source actually has the real handlers (not just the old static text), and
 * CheckoutWizard really does read the query param and act on it correctly.
 */
class TripDetailsTravelerCountTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{tenant: Tenant, instance: TripInstance}
     */
    private function makeFixture(string $suffix, int $availableSeats = 20): array
    {
        $tenant = Tenant::create(['name' => "Agency {$suffix}", 'slug' => "agency-travelers-{$suffix}"]);
        $template = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'Trip', 'base_price' => 500]);
        $instance = TripInstance::create([
            'tenant_id' => $tenant->id, 'trip_template_id' => $template->id,
            'start_date' => now()->addDays(5), 'end_date' => now()->addDays(10),
            'available_seats' => $availableSeats, 'status' => 'active',
        ]);
        TripPassengerCategory::create(['tenant_id' => $tenant->id, 'trip_instance_id' => $instance->id, 'name' => 'Adult', 'price' => 500, 'requires_seat' => true]);

        return compact('tenant', 'instance');
    }

    // ------------------------------------------------------------------
    // Source-level proof the static, unwired markup is gone and real handlers exist
    // ------------------------------------------------------------------

    public function test_trip_details_no_longer_has_the_static_unwired_traveler_text(): void
    {
        $source = file_get_contents(resource_path('views/livewire/trip-details.blade.php'));

        // The exact old markup: a styled-to-look-clickable div with zero click handler and a
        // hardcoded "1 بالغ" that could never change.
        $this->assertStringNotContainsString('1 بالغ</p>', $source);
        $this->assertStringContainsString('travelerCount', $source, 'A real Alpine state variable must back the traveler count now.');
        $this->assertStringContainsString("@click=\"travelerCount = Math.max(1, travelerCount - 1)\"", $source);
        $this->assertStringContainsString("@click=\"travelerCount = Math.min(maxTravelers, travelerCount + 1)\"", $source);
        $this->assertStringContainsString('x-text="travelerCount"', $source, 'The displayed number must actually be bound to the reactive count, not static text.');
        $this->assertStringContainsString('travelers=', $source, 'The chosen count must be threaded through to the checkout link.');
    }

    // ------------------------------------------------------------------
    // CheckoutWizard actually reads ?travelers=N and pre-adds that many passenger rows
    // ------------------------------------------------------------------

    public function test_checkout_defaults_to_one_passenger_when_no_travelers_param_is_present(): void
    {
        $f = $this->makeFixture('001');

        $component = Livewire::test(CheckoutWizard::class, ['tenant' => $f['tenant'], 'tripInstance' => $f['instance']]);

        $this->assertCount(1, $component->get('form.passengers'), 'Unchanged default behavior for any link that does not pass ?travelers=.');
    }

    public function test_checkout_pre_adds_the_requested_number_of_passenger_rows(): void
    {
        $f = $this->makeFixture('002');

        $component = Livewire::withQueryParams(['travelers' => 3])
            ->test(CheckoutWizard::class, ['tenant' => $f['tenant'], 'tripInstance' => $f['instance']]);

        $this->assertCount(3, $component->get('form.passengers'));
    }

    public function test_checkout_caps_the_requested_traveler_count_at_remaining_seats(): void
    {
        $f = $this->makeFixture('003', availableSeats: 2);

        $component = Livewire::withQueryParams(['travelers' => 10])
            ->test(CheckoutWizard::class, ['tenant' => $f['tenant'], 'tripInstance' => $f['instance']]);

        $this->assertCount(2, $component->get('form.passengers'), 'A stale/tampered query value must not request more passenger rows than the trip can actually seat.');
    }

    public function test_checkout_ignores_a_non_numeric_or_zero_travelers_param_and_falls_back_to_one(): void
    {
        $f = $this->makeFixture('004');

        $component = Livewire::withQueryParams(['travelers' => 'not-a-number'])
            ->test(CheckoutWizard::class, ['tenant' => $f['tenant'], 'tripInstance' => $f['instance']]);

        $this->assertCount(1, $component->get('form.passengers'));
    }
}
