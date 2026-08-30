<?php

namespace Tests\Feature;

use App\Livewire\StorefrontCatalog;
use App\Livewire\TripDetails;
use App\Models\Tenant;
use App\Models\TripInstance;
use App\Models\TripPassengerCategory;
use App\Models\TripTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Trip card placeholder-art + catalog filter-bar redesign ticket. Regression coverage for the two
 * genuinely functional pieces: the placeholder component's deterministic variant selection (so
 * different trips without photos look visually distinct, not the same identical box repeated),
 * and that the real-image/placeholder branch still picks correctly at both call sites. The filter
 * bar restructure reuses the exact same wire:model/wire:click bindings the sidebar already had --
 * covered by tests/Feature/StorefrontPhaseFTest.php's existing filter tests passing unmodified,
 * not duplicated here. Pill/popover styling itself is pure CSS/Alpine -- covered by live browser
 * verification instead (see the commit message).
 */
class StorefrontPlaceholderArtTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenant(string $suffix): Tenant
    {
        return Tenant::create(['name' => "Agency {$suffix}", 'slug' => "agency-placeholder-{$suffix}"]);
    }

    public function test_the_placeholder_component_picks_a_deterministic_variant_from_its_seed(): void
    {
        $this->blade('<x-trip-cover-placeholder :seed="$seed" />', ['seed' => 0])
            ->assertSee('data-variant="0"', false);

        $this->blade('<x-trip-cover-placeholder :seed="$seed" />', ['seed' => 1])
            ->assertSee('data-variant="1"', false);

        $this->blade('<x-trip-cover-placeholder :seed="$seed" />', ['seed' => 6])
            ->assertSee('data-variant="2"', false);

        $this->blade('<x-trip-cover-placeholder :seed="$seed" />', ['seed' => 99])
            ->assertSee('data-variant="3"', false);
    }

    public function test_the_placeholder_component_is_an_honest_marker_not_a_fake_photo(): void
    {
        $view = $this->blade('<x-trip-cover-placeholder :seed="$seed" />', ['seed' => 3]);

        $view->assertSee('الصورة قريباً');
        $view->assertDontSee('<img', false);
    }

    private function makeCatalogFixture(Tenant $tenant): TripTemplate
    {
        $template = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'Trip', 'base_price' => 100, 'is_active' => true]);
        $instance = TripInstance::create([
            'tenant_id' => $tenant->id, 'trip_template_id' => $template->id,
            'start_date' => now()->addDays(10), 'end_date' => now()->addDays(15),
            'available_seats' => 10, 'status' => 'active',
        ]);
        TripPassengerCategory::create(['tenant_id' => $tenant->id, 'trip_instance_id' => $instance->id, 'name' => 'Adult', 'price' => 100, 'requires_seat' => true]);

        return $template;
    }

    public function test_the_catalog_card_renders_the_placeholder_when_the_template_has_no_cover_media(): void
    {
        $tenant = $this->makeTenant('001');
        $template = $this->makeCatalogFixture($tenant);

        $html = Livewire::test(StorefrontCatalog::class, ['tenant' => $tenant])->html();

        $this->assertStringContainsString('trip-cover-placeholder', $html);
        $this->assertStringContainsString('data-variant="'.($template->id % 4).'"', $html);
    }

    public function test_trip_details_renders_the_placeholder_in_the_hero_when_the_template_has_no_media(): void
    {
        $tenant = $this->makeTenant('002');
        $template = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'Trip', 'base_price' => 100]);

        $html = Livewire::test(TripDetails::class, ['tenant' => $tenant, 'tripTemplate' => $template])->html();

        $this->assertStringContainsString('trip-cover-placeholder', $html);
    }

    public function test_the_catalog_card_renders_a_real_image_instead_of_the_placeholder_once_a_cover_photo_exists(): void
    {
        $tenant = $this->makeTenant('003');
        $template = $this->makeCatalogFixture($tenant);
        $template->addMedia(UploadedFile::fake()->image('cover.jpg', 1200, 800))->toMediaCollection('cover');

        $html = Livewire::test(StorefrontCatalog::class, ['tenant' => $tenant])->html();

        $this->assertStringNotContainsString('trip-cover-placeholder', $html);
        $this->assertStringContainsString('<img', $html);
    }

    public function test_trip_details_renders_a_real_image_instead_of_the_placeholder_once_a_cover_photo_exists(): void
    {
        $tenant = $this->makeTenant('004');
        $template = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'Trip', 'base_price' => 100]);
        $template->addMedia(UploadedFile::fake()->image('cover.jpg', 1200, 800))->toMediaCollection('cover');

        $html = Livewire::test(TripDetails::class, ['tenant' => $tenant, 'tripTemplate' => $template])->html();

        $this->assertStringNotContainsString('trip-cover-placeholder', $html);
    }
}
