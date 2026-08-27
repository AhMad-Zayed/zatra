<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\TripInstance;
use App\Models\TripPassengerCategory;
use App\Models\TripTemplate;
use App\Services\CreateBookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression coverage for the storefront redesign's Phase A (shared header/nav/footer +
 * brand tokens). Two things are covered here that are meaningfully testable server-side:
 *
 * 1. The nav-visibility fix: the unscrolled nav is transparent with white text, meant to overlay
 *    the dark full-height hero image that only the catalog homepage actually has. Every other
 *    page has a plain white/light background from the start, so that same styling rendered as
 *    invisible white-on-white logo/nav text -- live-confirmed while verifying Phase A on 8 of the
 *    9 shared-layout screens. Fixed by pre-setting Alpine's `scrolled` state based on the current
 *    route, server-rendered into the page, which this test asserts directly on the HTML.
 *
 * 2. The magic-link booking portal (CustomerBookingPortal, `/b/{uuid}`) must keep working for a
 *    completely anonymous, zero-cookie visitor -- it was the subject of an emergency hotfix
 *    earlier this session (removing auth:customer middleware to restore anonymous access), and
 *    Phase A's shared-layout work is explicitly required not to regress that.
 *
 * Not covered here: App\Livewire\TourGuideManifest (`/g/{uuid}`) was found to already be broken
 * (500, "Undefined variable $currentTenant") independent of any Phase A change -- confirmed by
 * reproducing it against the pre-Phase-A layout file via git stash. It's a pre-existing bug
 * outside this session's explicit scope for that screen ("confirm it isn't visually broken," not
 * a fix mandate), so no test asserts around it either way.
 */
class StorefrontSharedLayoutTest extends TestCase
{
    use RefreshDatabase;

    private function makeFixture(): array
    {
        $tenant = Tenant::create(['name' => 'Agency Layout', 'slug' => 'agency-layout']);
        $template = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'Layout Trip', 'base_price' => 500, 'is_active' => true]);
        $instance = TripInstance::create([
            'tenant_id' => $tenant->id, 'trip_template_id' => $template->id,
            'start_date' => now()->addDays(20), 'end_date' => now()->addDays(25),
            'available_seats' => 20, 'status' => 'active',
        ]);
        $category = TripPassengerCategory::create([
            'tenant_id' => $tenant->id, 'trip_instance_id' => $instance->id,
            'name' => 'Adult', 'price' => 500, 'requires_seat' => true,
        ]);

        return compact('tenant', 'template', 'instance', 'category');
    }

    public function test_catalog_homepage_keeps_the_transparent_hero_overlay_nav(): void
    {
        $f = $this->makeFixture();

        $response = $this->get(route('storefront.catalog', ['tenant' => $f['tenant']->slug]));

        $response->assertOk();
        $response->assertSee('scrolled: false', false);
    }

    public function test_every_other_shared_layout_screen_starts_with_a_readable_opaque_nav(): void
    {
        $f = $this->makeFixture();

        $screens = [
            route('storefront.trip.details', ['tenant' => $f['tenant']->slug, 'tripTemplate' => $f['template']->slug]),
            route('storefront.checkout', ['tenant' => $f['tenant']->slug, 'tripInstance' => $f['instance']->id]),
            route('portal.login', ['tenant' => $f['tenant']->slug]),
        ];

        foreach ($screens as $url) {
            $response = $this->get($url);
            $response->assertOk();
            $response->assertSee('scrolled: true', false);
        }
    }

    public function test_magic_link_booking_portal_is_reachable_by_a_completely_anonymous_visitor(): void
    {
        $f = $this->makeFixture();
        $customer = Customer::create(['tenant_id' => $f['tenant']->id, 'name' => 'Test Customer', 'phone' => '+966500000088']);

        $booking = app(CreateBookingService::class)->execute([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'customer_id' => $customer->id,
            'passengersData' => [
                ['trip_passenger_category_id' => $f['category']->id, 'first_name' => 'Test', 'last_name' => 'Passenger'],
            ],
        ]);

        // No Auth::login(), no session cookie set -- a genuinely anonymous request, matching a
        // real customer clicking a magic link from WhatsApp/SMS on a device that's never visited
        // this site before.
        $response = $this->get(route('customer.booking.portal', ['uuid' => $booking->uuid]));

        $response->assertOk();
        $response->assertSee('scrolled: true', false); // the portal is not the catalog homepage
        $response->assertSee($f['tenant']->name);
    }

    public function test_magic_link_booking_portal_shows_the_shared_nav_and_footer(): void
    {
        $f = $this->makeFixture();
        $customer = Customer::create(['tenant_id' => $f['tenant']->id, 'name' => 'Test Customer 2', 'phone' => '+966500000089']);

        $booking = app(CreateBookingService::class)->execute([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'customer_id' => $customer->id,
            'passengersData' => [
                ['trip_passenger_category_id' => $f['category']->id, 'first_name' => 'Test', 'last_name' => 'Passenger'],
            ],
        ]);

        $response = $this->get(route('customer.booking.portal', ['uuid' => $booking->uuid]));

        $response->assertOk();
        $response->assertSeeInOrder(['<nav', '<footer'], false);
    }
}
