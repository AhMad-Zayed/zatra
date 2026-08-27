<?php

namespace Tests\Feature;

use App\Livewire\StorefrontCatalog;
use App\Models\Tenant;
use App\Models\TripInstance;
use App\Models\TripTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regression coverage for a live-reproduced storefront bug (docs/STOREFRONT_UX_AUDIT.md,
 * Friction Point #1): the destination and date search filters in
 * StorefrontCatalog::render() were built against the wrong base model (TripTemplate instead of
 * TripInstance), throwing a BadMethodCallException the moment a customer typed anything into
 * either field. Both filters now query relative to TripTemplate correctly.
 */
class StorefrontCatalogSearchTest extends TestCase
{
    use RefreshDatabase;

    private function makeFixture(): array
    {
        $tenant = Tenant::create(['name' => 'Agency Catalog', 'slug' => 'agency-catalog']);

        $template = TripTemplate::create([
            'tenant_id' => $tenant->id,
            'title' => 'Maldives Luxury Package',
            'base_price' => 100,
            'is_active' => true,
        ]);
        $instance = TripInstance::create([
            'tenant_id' => $tenant->id,
            'trip_template_id' => $template->id,
            'start_date' => now()->addDays(30),
            'end_date' => now()->addDays(35),
            'available_seats' => 20,
            'status' => 'active',
        ]);

        return compact('tenant', 'template', 'instance');
    }

    public function test_typing_a_destination_search_term_does_not_crash_the_page(): void
    {
        $f = $this->makeFixture();

        Livewire::test(StorefrontCatalog::class, ['tenant' => $f['tenant']])
            ->set('searchDestination', 'Paris')
            ->assertOk();
    }

    public function test_destination_search_correctly_filters_by_trip_title(): void
    {
        $f = $this->makeFixture();

        Livewire::test(StorefrontCatalog::class, ['tenant' => $f['tenant']])
            ->set('searchDestination', 'Maldives')
            ->assertSee('Maldives Luxury Package');

        Livewire::test(StorefrontCatalog::class, ['tenant' => $f['tenant']])
            ->set('searchDestination', 'Nonexistent City XYZ')
            ->assertDontSee('Maldives Luxury Package');
    }

    public function test_typing_a_search_date_does_not_crash_the_page(): void
    {
        $f = $this->makeFixture();

        Livewire::test(StorefrontCatalog::class, ['tenant' => $f['tenant']])
            ->set('searchDate', now()->addDays(10)->toDateString())
            ->assertOk();
    }

    public function test_date_search_correctly_filters_by_trip_instance_departure(): void
    {
        $f = $this->makeFixture();

        // Instance departs in 30 days -- a date filter of "now" should still include it.
        Livewire::test(StorefrontCatalog::class, ['tenant' => $f['tenant']])
            ->set('searchDate', now()->toDateString())
            ->assertSee('Maldives Luxury Package');

        // A date filter after the instance's departure should exclude it.
        Livewire::test(StorefrontCatalog::class, ['tenant' => $f['tenant']])
            ->set('searchDate', now()->addDays(60)->toDateString())
            ->assertDontSee('Maldives Luxury Package');
    }
}
