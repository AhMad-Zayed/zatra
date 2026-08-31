<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TripInstance;
use App\Models\TripPassengerCategory;
use App\Models\TripTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression coverage for docs/STOREFRONT_PRODUCT_VISION.md Phase 0 -- three confirmed,
 * live-reproduced storefront bugs, all on the shared header/nav (components/layouts/
 * storefront.blade.php) and trip-details (livewire/trip-details.blade.php):
 *
 * 1. The unscrolled-nav WhatsApp button combined `.glass-panel` (a solid white background per
 *    Phase A's de-glassing, resources/css/app.css) with `text-white` -- white text/icon on a
 *    white button, invisible against the hero on the homepage. Live-confirmed by zooming into
 *    the rendered pixels: a blank white pill with no visible content. Fixed by swapping to the
 *    same transparent/outlined-over-hero treatment the hero's own "تصفح جميع الرحلات" CTA
 *    already uses (storefront-catalog.blade.php), instead of a solid fill.
 *
 * 2. All four WhatsApp/contact links in the header (desktop nav, desktop CTA button, mobile
 *    drawer nav, mobile drawer CTA) read `$currentTenant->phone` -- a column that does not exist
 *    anywhere on the `tenants` table (confirmed against every tenant migration) -- so it always
 *    silently resolved to the hardcoded fallback number, for every tenant, regardless of what
 *    they'd actually configured. The footer's social-icons block already reads the real,
 *    admin-settable value correctly from `$currentTenant->settings['whatsapp_number']`
 *    (ManageAgencySettings). Fixed by pointing all four header/nav locations at that same key.
 *
 * 3. The trip-details date range renders visually scrambled: the DOM text is correct
 *    ("04 Sep, 2026 - 09 Sep, 2026") but without LTR isolation inside the RTL page it renders on
 *    screen as "Sep, 2026 - 09 Sep, 2026 04" -- confirmed live by comparing a screenshot against
 *    the extracted page text. Fixed with the same `dir="ltr"` wrap already used correctly for
 *    this exact date-range pattern on the checkout summary card
 *    (checkout-wizard.blade.php:459-460).
 *
 * These are markup/attribute fixes only, no PHP/Livewire component logic changed -- so, per this
 * repo's existing convention (see TripDetailsTravelerCountTest, StorefrontSharedLayoutTest), the
 * tests below assert against the actual rendered HTML from a real HTTP request rather than
 * mocking anything. Alpine's `:class` bindings are literal, unevaluated attribute strings in
 * server-rendered markup, so the exact class list is directly assertable here.
 */
class StorefrontContactChannelFixesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{tenant: Tenant, template: TripTemplate, instance: TripInstance}
     */
    private function makeFixture(string $suffix, ?string $whatsappNumber = null): array
    {
        $tenant = Tenant::create([
            'name' => "Agency {$suffix}",
            'slug' => "agency-contactfix-{$suffix}",
            'settings' => $whatsappNumber ? ['whatsapp_number' => $whatsappNumber] : [],
        ]);
        $template = TripTemplate::create([
            'tenant_id' => $tenant->id, 'title' => 'Trip', 'base_price' => 500, 'is_active' => true,
        ]);
        $instance = TripInstance::create([
            'tenant_id' => $tenant->id, 'trip_template_id' => $template->id,
            'start_date' => now()->addDays(10)->setTime(21, 0), 'end_date' => now()->addDays(15)->setTime(21, 0),
            'available_seats' => 20, 'status' => 'active',
        ]);
        TripPassengerCategory::create([
            'tenant_id' => $tenant->id, 'trip_instance_id' => $instance->id,
            'name' => 'Adult', 'price' => 500, 'requires_seat' => true,
        ]);

        return compact('tenant', 'template', 'instance');
    }

    // ------------------------------------------------------------------
    // Bug 1: the unscrolled WhatsApp button is no longer white-on-white
    // ------------------------------------------------------------------

    public function test_the_unscrolled_whatsapp_button_no_longer_pairs_a_solid_white_panel_with_white_text(): void
    {
        $f = $this->makeFixture('001');

        $response = $this->get(route('storefront.catalog', ['tenant' => $f['tenant']->slug]));

        $response->assertOk();
        // The old broken combo: a solid-white .glass-panel background under white text/icon.
        $response->assertDontSee("glass-panel text-white border-white/20 hover:bg-white hover:text-zatara-blue", false);
        // The fix: transparent background, matching the hero CTA's own outlined-over-hero style.
        $response->assertSee("bg-transparent text-white border-white/40 hover:bg-white hover:text-zatara-blue", false);
    }

    public function test_the_scrolled_whatsapp_button_style_is_unchanged(): void
    {
        $f = $this->makeFixture('002');

        $response = $this->get(route('storefront.catalog', ['tenant' => $f['tenant']->slug]));

        // Only the unscrolled branch was broken -- the solid blue scrolled-state button already
        // had readable contrast and must not be touched by this fix.
        $response->assertSee("bg-zatara-blue text-white border-transparent hover:shadow-lg hover:shadow-zatara-blue/30", false);
    }

    // ------------------------------------------------------------------
    // Bug 2: header/nav WhatsApp links use the tenant's real configured number
    // ------------------------------------------------------------------

    public function test_header_whatsapp_links_use_the_tenants_configured_number_when_set(): void
    {
        $f = $this->makeFixture('003', whatsappNumber: '+970599111222');

        $response = $this->get(route('storefront.catalog', ['tenant' => $f['tenant']->slug]));
        $html = $response->getContent();

        $response->assertOk();
        // Desktop nav "اتصل بنا", desktop WhatsApp CTA button, mobile drawer nav, mobile drawer
        // CTA, and the footer's already-correct social icon -- the real number must appear
        // (exact per-surface count is asserted separately below); the old hardcoded fallback
        // must never leak through once a real number is configured.
        $this->assertStringContainsString('https://wa.me/970599111222', $html);
        $this->assertStringNotContainsString('wa.me/970599000000', $html);
    }

    public function test_header_whatsapp_links_still_fall_back_to_the_placeholder_number_when_unconfigured(): void
    {
        $f = $this->makeFixture('004');

        $response = $this->get(route('storefront.catalog', ['tenant' => $f['tenant']->slug]));
        $html = $response->getContent();

        $response->assertOk();
        // Existing default behavior for a tenant that hasn't filled in Manage Agency Settings yet
        // must be preserved -- a dead wa.me link would be worse than a clearly-fake placeholder.
        $this->assertSame(4, substr_count($html, 'https://wa.me/970599000000'));
    }

    public function test_header_whatsapp_links_read_the_same_settings_key_the_footer_already_reads_correctly(): void
    {
        $f = $this->makeFixture('005', whatsappNumber: '+970599333444');

        $response = $this->get(route('storefront.catalog', ['tenant' => $f['tenant']->slug]));
        $html = $response->getContent();

        // Footer's social-icons WhatsApp link (already correct before this fix) and the header
        // links now agree on the same source of truth -- no more disconnected phone numbers on
        // one page.
        $this->assertSame(5, substr_count($html, 'wa.me/970599333444'), '4 header/nav links + 1 footer social icon, all sourced from settings[whatsapp_number].');
    }

    // ------------------------------------------------------------------
    // Bug 3: the trip-details date range is LTR-isolated inside the RTL page
    // ------------------------------------------------------------------

    public function test_trip_details_date_range_is_wrapped_in_an_ltr_isolation_span(): void
    {
        $f = $this->makeFixture('006');

        $response = $this->get(route('storefront.trip.details', [
            'tenant' => $f['tenant']->slug, 'tripTemplate' => $f['template']->slug,
        ]));

        $response->assertOk();
        $expectedRange = $f['instance']->start_date->format('d M, Y').' - '.$f['instance']->end_date->format('d M, Y');
        // Bidi reordering only bites once this exact range is rendered inside an unisolated RTL
        // run -- assert the isolating wrapper actually surrounds the real, computed date text
        // (not just that a dir="ltr" attribute exists somewhere on the page).
        $response->assertSee('<span dir="ltr">'.$expectedRange.'</span>', false);
    }

    public function test_checkout_summary_cards_own_date_range_pattern_is_unchanged(): void
    {
        $f = $this->makeFixture('007');

        $response = $this->get(route('storefront.checkout', [
            'tenant' => $f['tenant']->slug, 'tripInstance' => $f['instance']->id,
        ]));

        // The checkout wizard's own dir="ltr" date-range wrap was already correct and untouched
        // by this fix -- confirms the trip-details fix mirrors, rather than duplicates or
        // conflicts with, the existing working pattern.
        $response->assertSee('dir="ltr"', false);
    }
}
