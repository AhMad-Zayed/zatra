<?php

namespace Tests\Feature;

use App\Livewire\StorefrontCatalog;
use App\Models\Tenant;
use App\Models\TripInstance;
use App\Models\TripPassengerCategory;
use App\Models\TripTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regression coverage for an N+1 query regression measured while investigating the storefront
 * redesign (Phase 0, Section D): TripTemplate::getStartingPriceAttribute() (added in the
 * STOREFRONT_UX_AUDIT price-display fix) queried tripInstances/tripPassengerCategories fresh for
 * every template card whenever base_price was 0, regardless of what the caller had already
 * eager-loaded. Measured directly against the real catalog render: 5 queries -> 11 for a single
 * card. Fixed by having the accessor reuse an already-loaded tripInstances relation, and by
 * eager-loading tripPassengerCategories alongside it in StorefrontCatalog::render().
 *
 * The regression test asserts the real signature of an N+1 -- that the query count for
 * trip_passenger_categories does NOT scale with the number of templates on the page -- rather
 * than a specific total query count, since several other pre-existing, unrelated per-card queries
 * (media, inventory_ledgers) also scale with template count and are out of scope here.
 */
class StorefrontCatalogPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private function makeTemplateWithZeroBasePriceAndCategory(Tenant $tenant, string $title, float $categoryPrice): TripTemplate
    {
        $template = TripTemplate::create([
            'tenant_id' => $tenant->id, 'title' => $title, 'base_price' => 0, 'is_active' => true,
        ]);
        $instance = TripInstance::create([
            'tenant_id' => $tenant->id, 'trip_template_id' => $template->id,
            'start_date' => now()->addDays(20), 'end_date' => now()->addDays(25),
            'available_seats' => 20, 'status' => 'active',
        ]);
        TripPassengerCategory::create([
            'tenant_id' => $tenant->id, 'trip_instance_id' => $instance->id,
            'name' => 'Adult', 'price' => $categoryPrice, 'requires_seat' => true,
        ]);

        return $template;
    }

    /**
     * Renders the component directly (mount + render, one request cycle) rather than through
     * Livewire::test() -- the testing harness renders internally more than once per call (for its
     * own snapshot/diff bookkeeping), which doubles every query count and would make a real N+1
     * indistinguishable from correct eager-loading. A direct render matches what one real HTTP
     * request actually does.
     */
    private function renderCatalog(Tenant $tenant): string
    {
        $component = new StorefrontCatalog();
        $component->tenant = $tenant;

        return (string) $component->render();
    }

    public function test_trip_passenger_categories_query_count_does_not_scale_with_template_count(): void
    {
        $tenant = Tenant::create(['name' => 'Agency Perf', 'slug' => 'agency-perf']);
        $this->makeTemplateWithZeroBasePriceAndCategory($tenant, 'Trip One', 500);

        // "select ... from trip_passenger_categories" specifically -- a broader substring match
        // also catches the fixture's own INSERT statements below, which have nothing to do with
        // what the catalog page itself queries.
        $isEagerLoadSelect = fn ($q) => str_starts_with(strtolower($q['query']), 'select') && str_contains($q['query'], 'trip_passenger_categories');

        DB::enableQueryLog();
        $html = $this->renderCatalog($tenant);
        $this->assertStringContainsString('500', $html);
        $queriesForOneTemplate = collect(DB::getQueryLog())->filter($isEagerLoadSelect)->count();
        DB::flushQueryLog();

        $this->makeTemplateWithZeroBasePriceAndCategory($tenant, 'Trip Two', 700);
        DB::flushQueryLog();

        $html = $this->renderCatalog($tenant);
        $this->assertStringContainsString('500', $html);
        $this->assertStringContainsString('700', $html);
        $queriesForTwoTemplates = collect(DB::getQueryLog())->filter($isEagerLoadSelect)->count();

        $this->assertSame(1, $queriesForOneTemplate);
        $this->assertSame(
            $queriesForOneTemplate,
            $queriesForTwoTemplates,
            'trip_passenger_categories query count scaled with template count -- the N+1 regressed.'
        );
    }

    public function test_starting_price_still_shows_the_real_category_price_after_the_fix(): void
    {
        $tenant = Tenant::create(['name' => 'Agency Perf2', 'slug' => 'agency-perf2']);
        $this->makeTemplateWithZeroBasePriceAndCategory($tenant, 'Trip Price Check', 333);

        Livewire::test(StorefrontCatalog::class, ['tenant' => $tenant])
            ->assertSee('333')
            ->assertDontSee('0 دولار');
    }
}
