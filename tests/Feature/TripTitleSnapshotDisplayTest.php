<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Tenant;
use App\Models\TripInstance;
use App\Models\TripPassengerCategory;
use App\Models\TripTemplate;
use App\Models\User;
use App\Services\CreateBookingService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Price Integrity Audit, Finding B/C: renaming a trip template after booking used to silently
 * rewrite the trip title shown on the customer's own "My Bookings" page, the magic-link portal,
 * and the admin bookings table -- despite snapshot_trip_title/snapshot_start_date/
 * snapshot_end_date being correctly captured at booking time and just never consulted by these
 * 3 surfaces. Fixed to prefer the snapshot, live data only as a fallback.
 */
class TripTitleSnapshotDisplayTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{tenant: Tenant, template: TripTemplate, instance: TripInstance, cat: TripPassengerCategory, customer: Customer, booking: \App\Models\Booking}
     */
    private function makeFixture(string $suffix): array
    {
        $tenant = Tenant::create(['name' => "Agency {$suffix}", 'slug' => "agency-tts-{$suffix}"]);
        $template = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'Original Trip Title', 'base_price' => 100]);
        $instance = TripInstance::create([
            'tenant_id' => $tenant->id, 'trip_template_id' => $template->id,
            'start_date' => now()->addDays(10), 'end_date' => now()->addDays(15),
            'available_seats' => 20, 'status' => 'active',
        ]);
        $cat = TripPassengerCategory::create([
            'tenant_id' => $tenant->id, 'trip_instance_id' => $instance->id,
            'name' => 'Adult', 'price' => 100, 'requires_seat' => true,
        ]);
        $customer = Customer::create(['tenant_id' => $tenant->id, 'name' => 'Jane', 'phone' => "0598{$suffix}"]);

        $booking = app(CreateBookingService::class)->execute([
            'tenant_id' => $tenant->id, 'trip_instance_id' => $instance->id, 'customer_id' => $customer->id,
            'passengersData' => [['trip_passenger_category_id' => $cat->id, 'first_name' => 'Jane', 'last_name' => 'Doe']],
        ]);

        // Rename the trip AFTER booking -- the whole point of this audit.
        $template->update(['title' => 'RENAMED Trip Title']);

        return compact('tenant', 'template', 'instance', 'cat', 'customer', 'booking');
    }

    public function test_my_bookings_page_shows_the_original_snapshotted_title_not_the_renamed_one(): void
    {
        $f = $this->makeFixture('001');

        $response = $this->actingAs($f['customer'], 'customer')
            ->get(route('storefront.my-bookings', ['tenant' => $f['tenant']->slug]));

        $response->assertOk();
        $response->assertSee('Original Trip Title');
        $response->assertDontSee('RENAMED Trip Title');
    }

    public function test_customer_booking_portal_shows_the_original_snapshotted_title_not_the_renamed_one(): void
    {
        $f = $this->makeFixture('002');

        $response = $this->get(route('customer.booking.portal', $f['booking']->uuid));

        $response->assertOk();
        $response->assertSee('Original Trip Title');
        $response->assertDontSee('RENAMED Trip Title');
    }

    public function test_admin_bookings_table_shows_the_original_snapshotted_title_not_the_renamed_one(): void
    {
        $f = $this->makeFixture('003');

        Role::firstOrCreate(['name' => 'agency_admin']);
        Permission::firstOrCreate(['name' => 'panel_access_placeholder']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $admin = User::create(['name' => 'Admin', 'phone' => "0597{$f['tenant']->id}00"]);
        $admin->tenants()->attach($f['tenant']);
        setPermissionsTeamId($f['tenant']->id);
        $admin->assignRole('agency_admin');
        $this->actingAs($admin);
        Filament::setTenant($f['tenant'], true);

        // Checks the resolved column STATE for this booking's row specifically, not a raw HTML
        // string match against the whole page -- the page also legitimately contains the live
        // renamed title elsewhere (the trip_instance_id filter dropdown lists ALL current trips,
        // a correct and unrelated use of live data), so a page-wide assertDontSee would be a
        // false positive here.
        $table = \App\Filament\Resources\BookingResource::table(new \Filament\Tables\Table(new \App\Filament\Resources\BookingResource\Pages\ListBookings()));
        $column = null;
        foreach ($table->getColumns() as $c) {
            if ($c->getName() === 'snapshot_trip_title') {
                $column = $c;
            }
        }
        $this->assertNotNull($column, "Could not locate the 'snapshot_trip_title' column.");

        $state = $column->record($f['booking']->fresh())->getState();
        $this->assertSame('Original Trip Title', $state);
    }

    public function test_all_3_surfaces_fall_back_to_live_title_when_no_snapshot_exists(): void
    {
        // A booking predating snapshotting (snapshot_trip_title left null) -- the fallback path
        // must still work, not blank out the trip name entirely.
        $f = $this->makeFixture('004');
        \Illuminate\Support\Facades\DB::table('bookings')->where('id', $f['booking']->id)->update(['snapshot_trip_title' => null]);

        $myBookings = $this->actingAs($f['customer'], 'customer')
            ->get(route('storefront.my-bookings', ['tenant' => $f['tenant']->slug]));
        $myBookings->assertOk();
        $myBookings->assertSee('RENAMED Trip Title');

        $portal = $this->get(route('customer.booking.portal', $f['booking']->uuid));
        $portal->assertOk();
        $portal->assertSee('RENAMED Trip Title');
    }
}
