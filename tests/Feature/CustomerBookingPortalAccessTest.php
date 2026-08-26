<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Tenant;
use App\Models\TripInstance;
use App\Models\TripPassengerCategory;
use App\Models\TripTemplate;
use App\Services\CreateBookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * EMERGENCY FIX regression coverage: the real, WhatsApp-delivered "magic link" sent to every
 * phone-booking customer (CreateBooking.php: route('customer.booking.portal', $booking->uuid),
 * a plain unsigned URL, not a signed login link) was gated behind auth:customer middleware --
 * so a genuinely fresh customer clicking it hit Laravel's default unauthenticated redirect
 * trying to build a URL for a route literally named "login" (which doesn't exist in this app),
 * throwing a hard 500 on every single click. Confirmed live via a zero-cookie browser session
 * before this fix.
 *
 * These tests hit the real HTTP route (not Livewire::test(), which bypasses route middleware
 * entirely and is why the existing CustomerBookingPortal tests elsewhere in this app never
 * caught this) with zero authentication, proving an unauthenticated visitor can actually reach
 * and use the page -- exactly the real-world scenario.
 */
class CustomerBookingPortalAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{tenant: Tenant, customer: Customer, instance: TripInstance}
     */
    private function makeFixture(string $suffix): array
    {
        $tenant = Tenant::create([
            'name' => "Agency {$suffix}", 'slug' => "agency-cbp-{$suffix}", 'domain' => "{$suffix}.zatara.com",
            // Avoids AtlahubService's null-accountId TypeError when saveAll() dispatches the
            // WhatsApp notification job synchronously (QUEUE_CONNECTION=sync in testing) --
            // an unrelated, pre-existing gap (no test in this app has exercised a fully
            // successful saveAll() before), not something this hotfix is responsible for fixing.
            'settings' => ['atlahub_account_id' => 'test', 'atlahub_inbox_id' => 'test', 'atlahub_api_token' => 'test'],
        ]);
        $customer = Customer::create(['name' => 'Jane', 'phone' => "0593{$suffix}", 'tenant_id' => $tenant->id]);
        $template = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'Tour', 'base_price' => 100]);
        $instance = TripInstance::create([
            'tenant_id' => $tenant->id,
            'trip_template_id' => $template->id,
            'start_date' => now()->addDays(5),
            'end_date' => now()->addDays(10),
            'available_seats' => 20,
            'status' => 'active',
        ]);

        return compact('tenant', 'customer', 'instance');
    }

    public function test_an_unauthenticated_visitor_can_load_the_magic_link_page(): void
    {
        $f = $this->makeFixture('001');
        $cat = TripPassengerCategory::create([
            'tenant_id' => $f['tenant']->id, 'trip_instance_id' => $f['instance']->id,
            'name' => 'Adult', 'price' => 100, 'requires_seat' => true,
        ]);
        $booking = (new CreateBookingService())->execute([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'customer_id' => $f['customer']->id,
            'passengersData' => [
                ['trip_passenger_category_id' => $cat->id, 'first_name' => 'P', 'last_name' => '1'],
            ],
        ]);

        // No actingAs(), no customer guard login at all -- exactly the real scenario of a
        // customer clicking a WhatsApp link for the first time.
        $response = $this->get(route('customer.booking.portal', $booking->uuid));

        $response->assertOk();
        $response->assertSee($booking->pnr);
        $response->assertSee('Tour');
    }

    public function test_an_unauthenticated_visitor_can_complete_the_booking_through_the_livewire_component(): void
    {
        // "Use this page," not just load it: drive the actual Livewire component (which the
        // real route now serves without any auth gate) through to a successful save, proving
        // the page is genuinely usable end-to-end for a guest, not just renderable.
        $f = $this->makeFixture('002');
        $cat = TripPassengerCategory::create([
            'tenant_id' => $f['tenant']->id, 'trip_instance_id' => $f['instance']->id,
            'name' => 'Adult', 'price' => 100, 'requires_seat' => true,
        ]);
        $booking = (new CreateBookingService())->execute([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'customer_id' => $f['customer']->id,
            'passengersData' => [
                ['trip_passenger_category_id' => $cat->id, 'first_name' => 'P', 'last_name' => '1'],
            ],
        ]);
        $passenger = $booking->passengers()->first();

        \Livewire\Livewire::test(\App\Livewire\CustomerBookingPortal::class, ['uuid' => $booking->uuid])
            ->set("passengersData.{$passenger->id}.first_name", 'Jane')
            ->set("passengersData.{$passenger->id}.last_name", 'Doe')
            ->set('step', 3)
            ->call('saveAll');

        $this->assertSame('Jane', $passenger->fresh()->first_name);
        $this->assertTrue((bool) $passenger->fresh()->data_complete);
    }

    public function test_ticket_download_route_is_also_reachable_without_authentication(): void
    {
        $f = $this->makeFixture('003');
        $cat = TripPassengerCategory::create([
            'tenant_id' => $f['tenant']->id, 'trip_instance_id' => $f['instance']->id,
            'name' => 'Adult', 'price' => 100, 'requires_seat' => true,
        ]);
        $booking = (new CreateBookingService())->execute([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'customer_id' => $f['customer']->id,
            'passengersData' => [
                ['trip_passenger_category_id' => $cat->id, 'first_name' => 'P', 'last_name' => '1'],
            ],
        ]);

        // No ticket media generated in this fixture -- the route itself must still be reachable
        // (a clean, expected 404 "ticket not ready yet", not a 500 from the auth middleware).
        $response = $this->get(route('customer.ticket.download', $booking->uuid));

        // A clean, expected 404 ("ticket not ready yet") -- not a 500 from the auth middleware.
        $response->assertNotFound();
    }
}
