<?php

namespace Tests\Feature;

use App\Livewire\StorefrontCatalog;
use App\Livewire\TripDetails;
use App\Models\Tenant;
use App\Models\TripInstance;
use App\Models\TripTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * $template->duration_days was referenced on both the catalog card and trip-details' header meta
 * row (the zero-instances fallback) but never existed as a column, accessor, or any computed
 * property anywhere -- it always silently evaluated to null. Confirmed via the real seeded data
 * that instances of the same template share the same length in practice, and the catalog card
 * needs a duration before any specific instance is even chosen, so this is a real,
 * admin-editable TripTemplate-level column (migration
 * 2026_09_08_000001_add_duration_days_to_trip_templates), not a computed-from-an-instance value.
 * Where a specific instance *is* selected on trip-details, the quick-info bar added in the
 * previous content-density ticket already computes the real duration from that instance's own
 * dates -- unaffected by this column either way.
 */
class TripTemplateDurationDaysTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenant(string $suffix): Tenant
    {
        return Tenant::create(['name' => "Agency {$suffix}", 'slug' => "agency-duration-{$suffix}"]);
    }

    // ------------------------------------------------------------------
    // Catalog card
    // ------------------------------------------------------------------

    public function test_catalog_card_shows_the_real_duration_once_set(): void
    {
        $tenant = $this->makeTenant('001');
        $template = TripTemplate::create([
            'tenant_id' => $tenant->id, 'title' => 'Trip With Duration', 'base_price' => 100,
            'is_active' => true, 'duration_days' => 7,
        ]);
        // StorefrontCatalog only lists templates with at least one bookable instance.
        TripInstance::create([
            'tenant_id' => $tenant->id, 'trip_template_id' => $template->id,
            'start_date' => now()->addDays(10), 'end_date' => now()->addDays(16),
            'available_seats' => 10, 'status' => 'active',
        ]);

        Livewire::test(StorefrontCatalog::class, ['tenant' => $tenant])
            ->assertSee('7 أيام');
    }

    public function test_catalog_card_does_not_show_a_null_duration_when_unset(): void
    {
        $tenant = $this->makeTenant('002');
        $template = TripTemplate::create([
            'tenant_id' => $tenant->id, 'title' => 'Trip Without Duration', 'base_price' => 100,
            'is_active' => true,
        ]);
        TripInstance::create([
            'tenant_id' => $tenant->id, 'trip_template_id' => $template->id,
            'start_date' => now()->addDays(10), 'end_date' => now()->addDays(16),
            'available_seats' => 10, 'status' => 'active',
        ]);

        $html = Livewire::test(StorefrontCatalog::class, ['tenant' => $tenant])->html();

        $this->assertStringNotContainsString('أيام</span>', $html, 'No day count should render at all when duration_days is null -- not "null أيام" or "0 أيام".');
    }

    // ------------------------------------------------------------------
    // Trip-details header (the zero-instances fallback specifically)
    // ------------------------------------------------------------------

    public function test_trip_details_header_shows_the_real_duration_when_no_instances_exist(): void
    {
        $tenant = $this->makeTenant('003');
        $template = TripTemplate::create([
            'tenant_id' => $tenant->id, 'title' => 'Trip No Instances', 'base_price' => 100,
            'duration_days' => 5,
        ]);

        Livewire::test(TripDetails::class, ['tenant' => $tenant, 'tripTemplate' => $template])
            ->assertSee('5 أيام');
    }

    public function test_trip_details_header_falls_back_gracefully_when_duration_and_instances_are_both_absent(): void
    {
        $tenant = $this->makeTenant('004');
        $template = TripTemplate::create([
            'tenant_id' => $tenant->id, 'title' => 'Trip Truly Empty', 'base_price' => 100,
        ]);

        $html = Livewire::test(TripDetails::class, ['tenant' => $tenant, 'tripTemplate' => $template])->html();

        $this->assertStringNotContainsString('أيام</span>', $html);
        $this->assertStringContainsString('لا توجد مواعيد متاحة', $html, 'The pre-existing no-dates-available fallback must still show, unchanged.');
    }

    public function test_trip_details_with_a_selected_instance_uses_the_real_instance_dates_not_the_template_default(): void
    {
        // When an instance IS selected, the quick-info bar (previous ticket) already computes
        // duration from that instance's own start/end dates -- a deliberately different value
        // from the template's advertised default here, to prove this column doesn't interfere
        // with or get confused for that separate, more precise calculation.
        $tenant = $this->makeTenant('005');
        $template = TripTemplate::create([
            'tenant_id' => $tenant->id, 'title' => 'Trip With Instance', 'base_price' => 100,
            'duration_days' => 99, // deliberately implausible, must NOT appear anywhere
        ]);
        TripInstance::create([
            'tenant_id' => $tenant->id, 'trip_template_id' => $template->id,
            'start_date' => now()->addDays(10), 'end_date' => now()->addDays(13), // 4 days / 3 nights
            'available_seats' => 10, 'status' => 'active',
        ]);

        $html = Livewire::test(TripDetails::class, ['tenant' => $tenant, 'tripTemplate' => $template])->html();

        $this->assertStringContainsString('4 أيام', $html, 'The real, computed per-instance duration must be shown.');
        $this->assertStringNotContainsString('99 أيام', $html, 'The unrelated template-level default must never leak in once a real instance exists.');
    }
}
