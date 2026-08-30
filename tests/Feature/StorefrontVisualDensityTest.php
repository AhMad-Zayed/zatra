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
 * Homepage/trip-details visual-density ticket -- regression coverage for the two genuinely
 * functional (not pure-CSS) pieces: the destination map's graceful degradation (new
 * destination_latitude/destination_longitude columns, migration
 * 2026_09_09_000001_add_destination_coordinates_to_trip_templates) and the homepage trust-signals
 * strip's conditional tourism-license card. Hover/transition polish and the glassmorphism search
 * card are pure CSS with nothing to assert in PHPUnit -- covered by live browser verification
 * instead (see the commit message).
 */
class StorefrontVisualDensityTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenant(string $suffix, ?string $license = null): Tenant
    {
        return Tenant::create([
            'name' => "Agency {$suffix}", 'slug' => "agency-density-{$suffix}",
            'tourism_license_number' => $license,
        ]);
    }

    // ------------------------------------------------------------------
    // Destination map -- graceful degradation
    // ------------------------------------------------------------------

    public function test_the_map_section_is_hidden_entirely_when_no_coordinates_are_set(): void
    {
        $tenant = $this->makeTenant('001');
        $template = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'Trip', 'base_price' => 100]);

        Livewire::test(TripDetails::class, ['tenant' => $tenant, 'tripTemplate' => $template])
            ->assertDontSee('الموقع على الخريطة');
    }

    public function test_the_map_section_stays_hidden_when_only_one_coordinate_is_set(): void
    {
        // Partial data must not render a broken/malformed map -- both or neither.
        $tenant = $this->makeTenant('002');
        $template = TripTemplate::create([
            'tenant_id' => $tenant->id, 'title' => 'Trip', 'base_price' => 100,
            'destination_latitude' => 25.2, // longitude deliberately left null
        ]);

        Livewire::test(TripDetails::class, ['tenant' => $tenant, 'tripTemplate' => $template])
            ->assertDontSee('الموقع على الخريطة');
    }

    public function test_the_map_section_renders_a_real_openstreetmap_embed_with_the_correct_coordinates_once_both_are_set(): void
    {
        $tenant = $this->makeTenant('003');
        $template = TripTemplate::create([
            'tenant_id' => $tenant->id, 'title' => 'Trip', 'base_price' => 100,
            'destination_latitude' => 3.2028, 'destination_longitude' => 73.2207,
        ]);

        $html = Livewire::test(TripDetails::class, ['tenant' => $tenant, 'tripTemplate' => $template])
            ->assertSee('الموقع على الخريطة')
            ->html();

        $this->assertStringContainsString('openstreetmap.org/export/embed.html', $html);
        $this->assertStringContainsString('marker=3.2028', $html);
        $this->assertStringContainsString('73.2207', $html);
        // No API key/credential parameter of any kind -- OSM's embed needs none.
        $this->assertStringNotContainsString('key=', $html);
        $this->assertStringNotContainsString('api_key', $html);
    }

    // ------------------------------------------------------------------
    // Homepage trust signals -- conditional tourism-license card
    // ------------------------------------------------------------------

    private function makeCatalogFixture(Tenant $tenant): void
    {
        $template = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'Trip', 'base_price' => 100, 'is_active' => true]);
        $instance = TripInstance::create([
            'tenant_id' => $tenant->id, 'trip_template_id' => $template->id,
            'start_date' => now()->addDays(10), 'end_date' => now()->addDays(15),
            'available_seats' => 10, 'status' => 'active',
        ]);
        TripPassengerCategory::create(['tenant_id' => $tenant->id, 'trip_instance_id' => $instance->id, 'name' => 'Adult', 'price' => 100, 'requires_seat' => true]);
    }

    public function test_the_license_trust_card_shows_the_real_license_number_when_set(): void
    {
        $tenant = $this->makeTenant('004', license: 'TL-998877');
        $this->makeCatalogFixture($tenant);

        Livewire::test(StorefrontCatalog::class, ['tenant' => $tenant])
            ->assertSee('ترخيص سياحي رسمي')
            ->assertSee('TL-998877');
    }

    public function test_the_license_trust_card_is_hidden_entirely_when_the_tenant_has_no_license_number(): void
    {
        $tenant = $this->makeTenant('005', license: null);
        $this->makeCatalogFixture($tenant);

        Livewire::test(StorefrontCatalog::class, ['tenant' => $tenant])
            ->assertDontSee('ترخيص سياحي رسمي');
    }

    public function test_the_other_three_trust_cards_always_show_regardless_of_license(): void
    {
        $tenant = $this->makeTenant('006', license: null);
        $this->makeCatalogFixture($tenant);

        Livewire::test(StorefrontCatalog::class, ['tenant' => $tenant])
            ->assertSee('دعم فوري عبر واتساب')
            ->assertSee('خيارات دفع مرنة')
            ->assertSee('إلغاء مرن');
    }
}
