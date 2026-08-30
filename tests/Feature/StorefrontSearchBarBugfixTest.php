<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TripInstance;
use App\Models\TripTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Live-reproduced user report: "the search bar is not working, in the top and in the hero too".
 * Three separate real bugs, confirmed with a real browser before any fix:
 *
 * 1. The header's search icon (components/layouts/storefront.blade.php) had zero click handler
 *    at all -- purely decorative, on every single storefront page.
 * 2. The hero's mobile search "bar" (livewire/storefront-catalog.blade.php) was a plain <button>
 *    with no wire:model bindings and no click handler -- 100% non-functional on mobile, the only
 *    entry point being a fake pill mimicking a search bar.
 * 3. Both date fields were type="text" with no real date-picker UI at all, so "picking a date"
 *    was literally impossible, and free-typed text that wasn't an exact ISO date silently matched
 *    every trip instead of filtering (no error, no visible feedback either way).
 *
 * The underlying desktop destination filter and StorefrontCatalog::render()'s query logic were
 * already correct (confirmed live and already covered by StorefrontCatalogSearchTest.php) -- this
 * ticket is a markup/Alpine fix only, no PHP/Livewire component changes.
 */
class StorefrontSearchBarBugfixTest extends TestCase
{
    use RefreshDatabase;

    private function makeFixture(string $suffix): array
    {
        $tenant = Tenant::create(['name' => "Agency {$suffix}", 'slug' => "agency-searchfix-{$suffix}"]);
        $template = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'Trip', 'base_price' => 100, 'is_active' => true]);
        $instance = TripInstance::create([
            'tenant_id' => $tenant->id, 'trip_template_id' => $template->id,
            'start_date' => now()->addDays(10), 'end_date' => now()->addDays(15),
            'available_seats' => 10, 'status' => 'active',
        ]);

        return compact('tenant', 'template', 'instance');
    }

    public function test_the_header_search_icon_is_a_functional_button_on_the_catalog_page(): void
    {
        $f = $this->makeFixture('001');

        $response = $this->get(route('storefront.catalog', ['tenant' => $f['tenant']->slug]));

        $response->assertOk();
        $response->assertSee('open-hero-search', false);
    }

    public function test_the_header_search_icon_is_a_real_link_to_the_catalog_on_other_pages(): void
    {
        $f = $this->makeFixture('002');

        $response = $this->get(route('storefront.trip.details', ['tenant' => $f['tenant']->slug, 'tripTemplate' => $f['template']->slug]));

        $response->assertOk();
        // The JS-driven "scroll to hero" behavior only makes sense on the page that actually has
        // a hero -- everywhere else it must be a plain link, not the dead button it used to be.
        $response->assertDontSee('open-hero-search', false);
        $response->assertSee(route('storefront.catalog', ['tenant' => $f['tenant']->slug]), false);
    }

    public function test_both_date_search_fields_are_real_native_date_inputs(): void
    {
        $f = $this->makeFixture('003');

        $response = $this->get(route('storefront.catalog', ['tenant' => $f['tenant']->slug]));

        $response->assertOk();
        $html = $response->getContent();

        // Mobile panel + desktop bar -- both must be real date pickers now.
        $this->assertSame(2, substr_count($html, 'type="date" wire:model.live="searchDate"'));
        $this->assertStringNotContainsString('type="text" wire:model.live="searchDate"', $html);
    }

    public function test_the_mobile_search_panel_has_real_bound_fields_not_a_dead_button(): void
    {
        $f = $this->makeFixture('004');

        $response = $this->get(route('storefront.catalog', ['tenant' => $f['tenant']->slug]));

        $response->assertOk();
        // mobileSearchOpen is the Alpine toggle driving the real panel -- its presence proves the
        // mobile pill is no longer a static decorative button.
        $response->assertSee('mobileSearchOpen', false);
        $response->assertSee('wire:model.live.debounce.300ms="searchDestination"', false);
    }
}
