<?php

namespace Tests\Feature;

use App\Filament\Resources\BookingResource;
use App\Filament\Resources\BookingResource\Pages\CreateBooking;
use App\Filament\Resources\BookingResource\Pages\ListBookings;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\TripInstance;
use App\Models\TripTemplate;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Form;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * CRITICAL HOTFIX regression coverage, follow-up to WaitingListResourceTenantIsolationTest: the
 * same unscoped TripInstance::query() leak pattern found in 3 more staff-facing pickers in
 * BookingResource.php -- the booking create/edit form's trip_instance_id Select, the bookings
 * table's trip_instance_id SelectFilter, and (most severe) the transfer_booking row action's
 * new_trip_instance_id Select, which let staff potentially move a real paid booking to a trip
 * belonging to ANOTHER tenant (BookingService::transferBooking() itself -- guardrail-protected,
 * not modified -- has no tenant check of its own on the destination trip, so this UI-level
 * picker was the only defense).
 *
 * A 4th, identical copy of the transfer_booking picker exists in
 * BookingResource/Pages/ViewBooking.php (the booking detail page's header action). It is fixed
 * identically but NOT covered by a behavioral test here: mounting that specific action crashes
 * with an unrelated, pre-existing bug (ViewBooking.php never imports App\Models\Booking, so
 * every `Booking $record` type-hint in that file's transfer_booking closures resolves to the
 * wrong, nonexistent class and throws a TypeError as soon as the action is mounted -- confirmed
 * reproducible, not something introduced by this fix). That bug makes the ENTIRE transfer_booking
 * feature on the booking detail page unusable today, independent of tenant scoping, and is
 * flagged separately as its own finding, not fixed here. A source-text assertion below confirms
 * the tenant-scope fix landed in that file too.
 */
class BookingResourceTripPickerTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function makeAgencyAdmin(Tenant $tenant, string $phone): User
    {
        Role::firstOrCreate(['name' => 'agency_admin']);
        Permission::firstOrCreate(['name' => 'panel_access_placeholder']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::create(['name' => 'Admin', 'phone' => $phone]);
        $user->tenants()->attach($tenant);
        setPermissionsTeamId($tenant->id);
        $user->assignRole('agency_admin');

        return $user;
    }

    /**
     * @return array{tenantA: Tenant, tenantB: Tenant, instanceA: TripInstance, instanceB: TripInstance, booking: Booking}
     */
    private function makeFixture(string $suffix): array
    {
        $tenantA = Tenant::create(['name' => "Agency A {$suffix}", 'slug' => "agency-a-brtp-{$suffix}"]);
        $tenantB = Tenant::create(['name' => "Agency B {$suffix}", 'slug' => "agency-b-brtp-{$suffix}"]);

        $templateA = TripTemplate::create(['tenant_id' => $tenantA->id, 'title' => 'Trip A', 'base_price' => 100]);
        $templateB = TripTemplate::create(['tenant_id' => $tenantB->id, 'title' => 'Trip B', 'base_price' => 100]);

        $instanceA = TripInstance::create([
            'tenant_id' => $tenantA->id, 'trip_template_id' => $templateA->id,
            'start_date' => now()->addDays(5), 'end_date' => now()->addDays(6),
            'available_seats' => 10, 'status' => 'active',
        ]);
        $instanceB = TripInstance::create([
            'tenant_id' => $tenantB->id, 'trip_template_id' => $templateB->id,
            'start_date' => now()->addDays(5), 'end_date' => now()->addDays(6),
            'available_seats' => 10, 'status' => 'active',
        ]);

        $admin = $this->makeAgencyAdmin($tenantA, "0500{$suffix}");
        $this->actingAs($admin);
        Filament::setTenant($tenantA, true);

        $customer = Customer::create(['tenant_id' => $tenantA->id, 'name' => 'Jane', 'phone' => "0599{$suffix}"]);
        $booking = Booking::create([
            'tenant_id' => $tenantA->id, 'trip_instance_id' => $instanceA->id, 'customer_id' => $customer->id,
            'pnr' => "TST-{$suffix}", 'currency' => 'USD', 'booking_status' => 'pending', 'payment_status' => 'unpaid',
        ]);

        return compact('tenantA', 'tenantB', 'instanceA', 'instanceB', 'booking');
    }

    /** Recurses into nested Group/Section containers to find a field by name. */
    private function findField(array $components, string $name)
    {
        foreach ($components as $component) {
            if (method_exists($component, 'getName') && $component->getName() === $name) {
                return $component;
            }
            if (method_exists($component, 'getChildComponentContainer')) {
                $found = $this->findField($component->getChildComponentContainer()->getComponents(), $name);
                if ($found) {
                    return $found;
                }
            }
        }

        return null;
    }

    public function test_create_booking_trip_picker_only_shows_the_current_tenants_trips(): void
    {
        $f = $this->makeFixture('001');

        $form = BookingResource::form(Form::make(new CreateBooking()));
        $field = $this->findField($form->getComponents(), 'trip_instance_id');
        $this->assertNotNull($field, "Could not locate the 'trip_instance_id' field.");

        $options = $field->getOptions();

        $this->assertArrayHasKey($f['instanceA']->id, $options);
        $this->assertArrayNotHasKey($f['instanceB']->id, $options, "Tenant A's booking-creation trip picker must NOT show Tenant B's trip.");
    }

    public function test_bookings_table_trip_filter_only_shows_the_current_tenants_trips(): void
    {
        $f = $this->makeFixture('002');

        $table = BookingResource::table(new Table(new \App\Filament\Resources\BookingResource\Pages\ListBookings()));
        $filter = $table->getFilter('trip_instance_id');
        $this->assertNotNull($filter);

        $ref = new \ReflectionClass($filter);
        $method = $ref->getMethod('getOptions');
        $method->setAccessible(true);
        $options = $method->invoke($filter);

        $this->assertArrayHasKey($f['instanceA']->id, $options);
        $this->assertArrayNotHasKey($f['instanceB']->id, $options, "Tenant A's bookings-table trip filter must NOT show Tenant B's trip.");
    }

    public function test_transfer_booking_action_picker_only_shows_the_current_tenants_trips(): void
    {
        $f = $this->makeFixture('003');

        $test = Livewire::test(ListBookings::class)
            ->mountTableAction('transfer_booking', $f['booking']);

        $form = $test->instance()->getMountedTableActionForm();
        $field = $this->findField($form->getComponents(), 'new_trip_instance_id');
        $this->assertNotNull($field, "Could not locate the 'new_trip_instance_id' field.");

        $options = $field->getOptions();

        $this->assertArrayNotHasKey($f['instanceB']->id, $options, "transfer_booking's destination picker must NOT show Tenant B's trip -- this is the picker that could move a real paid booking cross-tenant.");
    }

    public function test_view_booking_transfer_action_source_carries_the_same_tenant_scope_fix(): void
    {
        // Behavioral coverage is not possible here: mounting this specific action crashes on an
        // unrelated, pre-existing bug (see class docblock) independent of this fix. Confirms the
        // fix landed in this file too, matching the precedent set by
        // TripCancellationTest::test_trip_instances_relation_manager_status_select_excludes_cancelled
        // for exactly this kind of behaviorally-inaccessible-but-still-verifiable case.
        $source = file_get_contents(app_path('Filament/Resources/BookingResource/Pages/ViewBooking.php'));
        $selectStart = strpos($source, "Select::make('new_trip_instance_id')");
        $this->assertNotFalse($selectStart);
        $slice = substr($source, $selectStart, 1200);

        $this->assertStringContainsString("where('tenant_id', \$record->tenant_id)", $slice);
    }
}
