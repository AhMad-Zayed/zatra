<?php

namespace Tests\Feature;

use App\Livewire\StorefrontCatalog;
use App\Livewire\TripDetails;
use App\Models\Tenant;
use App\Models\TripInstance;
use App\Models\TripPassengerCategory;
use App\Models\TripTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regression coverage for a live-reproduced storefront bug (docs/STOREFRONT_UX_AUDIT.md,
 * Friction Point #2): the catalog card, trip details hero, and trip details booking widget all
 * displayed "0 دولار" for any trip priced entirely through TripPassengerCategory records instead
 * of TripTemplate.base_price (which is exactly how "Maldives Luxury Package" is priced). Fixed via
 * TripTemplate::getStartingPriceAttribute(), which falls back to the lowest passenger-category
 * price across the template's bookable instances whenever base_price is unset/zero.
 */
class StorefrontPriceDisplayTest extends TestCase
{
    use RefreshDatabase;

    private function makeFixture(float $basePrice = 0): array
    {
        $tenant = Tenant::create(['name' => 'Agency Pricing', 'slug' => 'agency-pricing']);

        $template = TripTemplate::create([
            'tenant_id' => $tenant->id,
            'title' => 'Maldives Luxury Package',
            'base_price' => $basePrice,
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
        TripPassengerCategory::create([
            'tenant_id' => $tenant->id,
            'trip_instance_id' => $instance->id,
            'name' => 'Adult',
            'price' => 5000,
            'requires_seat' => true,
        ]);

        return compact('tenant', 'template', 'instance');
    }

    public function test_starting_price_falls_back_to_the_lowest_category_price_when_base_price_is_zero(): void
    {
        $f = $this->makeFixture(basePrice: 0);

        $this->assertEquals(5000.0, $f['template']->fresh()->starting_price);
    }

    public function test_starting_price_prefers_base_price_when_it_is_set(): void
    {
        $f = $this->makeFixture(basePrice: 750);

        $this->assertEquals(750.0, $f['template']->fresh()->starting_price);
    }

    public function test_starting_price_uses_the_lowest_category_across_multiple_categories(): void
    {
        $f = $this->makeFixture(basePrice: 0);
        TripPassengerCategory::create([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'name' => 'Child',
            'price' => 2500,
            'requires_seat' => true,
        ]);

        $this->assertEquals(2500.0, $f['template']->fresh()->starting_price);
    }

    public function test_catalog_card_shows_the_real_price_not_zero(): void
    {
        $f = $this->makeFixture(basePrice: 0);

        Livewire::test(StorefrontCatalog::class, ['tenant' => $f['tenant']])
            ->assertSee('5,000')
            ->assertDontSee('0 دولار');
    }

    public function test_trip_details_page_shows_the_real_price_not_zero(): void
    {
        $f = $this->makeFixture(basePrice: 0);

        Livewire::test(TripDetails::class, ['tenant' => $f['tenant'], 'tripTemplate' => $f['template']])
            ->assertSee('5,000')
            ->assertDontSee('0 دولار');
    }
}
