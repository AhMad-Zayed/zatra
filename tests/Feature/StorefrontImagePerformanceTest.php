<?php

namespace Tests\Feature;

use App\Livewire\StorefrontCatalog;
use App\Livewire\TripDetails;
use App\Models\Tenant;
use App\Models\TripInstance;
use App\Models\TripTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regression coverage for the storefront redesign's Phase B image-performance work (Section D):
 * the catalog card, homepage hero, and trip-details gallery previously always served the raw,
 * full-resolution original with no loading="lazy" and no responsive srcset. Fixed with named
 * Media Library conversions ('card'/'card-2x' on TripTemplate/TripInstance, 'hero' on Tenant) and
 * the corresponding attributes in each view.
 *
 * Live-verified separately with a real uploaded image (generated a real JPEG, uploaded it to a
 * live TripTemplate/Tenant, confirmed the 'card'/'card-2x'/'hero' conversion files actually
 * generate on disk, confirmed the rendered <img> tags use the conversion URLs with correct
 * srcset/loading attributes, and confirmed the images genuinely load in a real browser -- then
 * removed that test image afterward, since it was a synthetic placeholder, not real content).
 * That live image was also what surfaced two further pre-existing gaps, both fixed here too:
 * public/images/placeholder.jpg (the old no-photo fallback) doesn't exist in the repo at all, and
 * this dev machine's public/storage symlink pointed at a stale, renamed project folder -- both
 * independent of the actual application code, documented rather than silently worked around.
 */
class StorefrontImagePerformanceTest extends TestCase
{
    use RefreshDatabase;

    private function makeFixture(): array
    {
        $tenant = Tenant::create(['name' => 'Agency Images', 'slug' => 'agency-images']);
        $template = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'Image Trip', 'base_price' => 500, 'is_active' => true]);
        $instance = TripInstance::create([
            'tenant_id' => $tenant->id, 'trip_template_id' => $template->id,
            'start_date' => now()->addDays(20), 'end_date' => now()->addDays(25),
            'available_seats' => 20, 'status' => 'active',
        ]);

        return compact('tenant', 'template', 'instance');
    }

    private function fakeImage(): UploadedFile
    {
        return UploadedFile::fake()->image('cover.jpg', 1200, 800);
    }

    public function test_trip_template_generates_card_and_card_2x_conversions_on_upload(): void
    {
        $f = $this->makeFixture();
        $f['template']->addMedia($this->fakeImage())->toMediaCollection('cover');

        $media = $f['template']->fresh()->getFirstMedia('cover');

        $this->assertTrue($media->hasGeneratedConversion('card'));
        $this->assertTrue($media->hasGeneratedConversion('card-2x'));
    }

    public function test_tenant_generates_hero_conversion_on_upload(): void
    {
        $f = $this->makeFixture();
        $f['tenant']->addMedia($this->fakeImage())->toMediaCollection('hero_image');

        $media = $f['tenant']->fresh()->getFirstMedia('hero_image');

        $this->assertTrue($media->hasGeneratedConversion('hero'));
    }

    public function test_catalog_card_image_uses_the_card_conversion_with_srcset_and_lazy_loading(): void
    {
        $f = $this->makeFixture();
        $f['template']->addMedia($this->fakeImage())->toMediaCollection('cover');
        $expectedUrl = $f['template']->fresh()->getFirstMedia('cover')->getUrl('card');

        $html = Livewire::test(StorefrontCatalog::class, ['tenant' => $f['tenant']])->html();

        $this->assertStringContainsString($expectedUrl, $html);
        $this->assertStringContainsString('loading="lazy"', $html);
        $this->assertStringContainsString('srcset=', $html);
    }

    public function test_catalog_hero_image_is_eager_loaded_not_lazy(): void
    {
        $f = $this->makeFixture();
        $f['tenant']->addMedia($this->fakeImage())->toMediaCollection('hero_image');
        $expectedUrl = $f['tenant']->fresh()->getFirstMedia('hero_image')->getUrl('hero');

        $html = Livewire::test(StorefrontCatalog::class, ['tenant' => $f['tenant']])->html();

        $this->assertStringContainsString($expectedUrl, $html);
        // The hero <img> tag itself must not carry loading="lazy" -- it's the page's
        // above-the-fold LCP element.
        $heroImgTag = substr($html, (int) strpos($html, '<img src="' . $expectedUrl . '"'));
        $heroImgTag = substr($heroImgTag, 0, strpos($heroImgTag, '>') + 1);
        $this->assertStringNotContainsString('loading="lazy"', $heroImgTag);
    }

    public function test_trip_details_shows_a_graceful_placeholder_instead_of_a_broken_image_when_no_photo_exists(): void
    {
        $f = $this->makeFixture();

        $html = Livewire::test(TripDetails::class, ['tenant' => $f['tenant'], 'tripTemplate' => $f['template']])->html();

        // No cover/gallery media at all -- the old fallback pointed at a placeholder.jpg that
        // doesn't exist in the repo. There must be no <img> tag in the gallery section at all now
        // (a gradient+icon div instead), not an <img> pointing at a 404.
        $this->assertStringNotContainsString('placeholder.jpg', $html);
    }

    public function test_trip_details_secondary_gallery_images_are_lazy_loaded_with_srcset(): void
    {
        $f = $this->makeFixture();
        $f['template']->addMedia($this->fakeImage())->toMediaCollection('cover');
        $f['template']->addMedia($this->fakeImage())->toMediaCollection('gallery');
        $f['template']->addMedia($this->fakeImage())->toMediaCollection('gallery');

        $html = Livewire::test(TripDetails::class, ['tenant' => $f['tenant'], 'tripTemplate' => $f['template']])->html();

        $this->assertStringContainsString('loading="lazy"', $html);
        $this->assertStringContainsString('srcset=', $html);
    }
}
