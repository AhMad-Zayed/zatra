<?php

namespace Tests\Feature;

use App\Filament\Resources\BookingResource\Pages\ViewBooking;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\TripInstance;
use App\Models\TripTemplate;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * EMERGENCY HOTFIX regression coverage: ViewBooking.php (the booking detail page) never imported
 * App\Models\Booking, so every `Booking $record` type-hint in its transfer_booking action's
 * closures resolved to the wrong, nonexistent class
 * (App\Filament\Resources\BookingResource\Pages\Booking) -- a TypeError as soon as the action was
 * mounted, breaking "transfer to another trip" entirely from a booking's detail page (the
 * identical row-action version on the bookings LIST page was unaffected -- that file already had
 * the correct import). Confirmed live via a fresh mountAction() call against the real dev
 * database before this fix; the identical call now mounts successfully after it.
 *
 * Discovered during the tenant-isolation leak audit (BookingResourceTripPickerTenantIsolationTest),
 * where it blocked behavioral tenant-scope coverage for this specific picker at the time.
 */
class ViewBookingTransferActionImportTest extends TestCase
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
     * @return array{tenant: Tenant, otherTenant: Tenant, instance: TripInstance, otherInstance: TripInstance, booking: Booking}
     */
    private function makeFixture(string $suffix): array
    {
        $tenant = Tenant::create(['name' => "Agency {$suffix}", 'slug' => "agency-vbti-{$suffix}"]);
        $otherTenant = Tenant::create(['name' => "Other Agency {$suffix}", 'slug' => "other-agency-vbti-{$suffix}"]);

        $template = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'Trip', 'base_price' => 100]);
        $instance = TripInstance::create([
            'tenant_id' => $tenant->id, 'trip_template_id' => $template->id,
            'start_date' => now()->addDays(5), 'end_date' => now()->addDays(6),
            'available_seats' => 10, 'status' => 'active',
        ]);

        $otherTemplate = TripTemplate::create(['tenant_id' => $otherTenant->id, 'title' => 'Other Trip', 'base_price' => 100]);
        $otherInstance = TripInstance::create([
            'tenant_id' => $otherTenant->id, 'trip_template_id' => $otherTemplate->id,
            'start_date' => now()->addDays(5), 'end_date' => now()->addDays(6),
            'available_seats' => 10, 'status' => 'active',
        ]);

        $admin = $this->makeAgencyAdmin($tenant, "0511{$suffix}");
        $this->actingAs($admin);
        Filament::setTenant($tenant, true);

        $customer = Customer::create(['tenant_id' => $tenant->id, 'name' => 'Jane', 'phone' => "0522{$suffix}"]);
        $booking = Booking::create([
            'tenant_id' => $tenant->id, 'trip_instance_id' => $instance->id, 'customer_id' => $customer->id,
            'pnr' => "VBTI-{$suffix}", 'currency' => 'USD', 'booking_status' => 'pending', 'payment_status' => 'unpaid',
        ]);

        return compact('tenant', 'otherTenant', 'instance', 'otherInstance', 'booking');
    }

    public function test_transfer_booking_action_mounts_successfully_from_the_booking_detail_page(): void
    {
        $f = $this->makeFixture('001');

        $test = Livewire::test(ViewBooking::class, ['record' => $f['booking']->getRouteKey()])
            ->mountAction('transfer_booking');

        $this->assertNotNull($test->instance()->getMountedAction(), 'transfer_booking must mount without crashing on the booking detail page.');
    }

    public function test_transfer_booking_destination_picker_is_reachable_and_tenant_scoped_from_the_detail_page(): void
    {
        $f = $this->makeFixture('002');

        // A second trip on the SAME tenant, so the picker has something real to show once the
        // booking's own trip is excluded.
        $sameTenantTemplate = TripTemplate::create(['tenant_id' => $f['tenant']->id, 'title' => 'Second Trip', 'base_price' => 100]);
        $sameTenantInstance = TripInstance::create([
            'tenant_id' => $f['tenant']->id, 'trip_template_id' => $sameTenantTemplate->id,
            'start_date' => now()->addDays(8), 'end_date' => now()->addDays(9),
            'available_seats' => 10, 'status' => 'active',
        ]);

        $test = Livewire::test(ViewBooking::class, ['record' => $f['booking']->getRouteKey()])
            ->mountAction('transfer_booking');

        $form = $test->instance()->getMountedActionForm();
        $field = null;
        foreach ($form->getComponents() as $component) {
            if (method_exists($component, 'getName') && $component->getName() === 'new_trip_instance_id') {
                $field = $component;
            }
        }
        $this->assertNotNull($field, "Could not locate the 'new_trip_instance_id' field -- confirms the form itself builds without crashing.");

        $options = $field->getOptions();

        $this->assertArrayHasKey($sameTenantInstance->id, $options);
        $this->assertArrayNotHasKey($f['otherInstance']->id, $options, "Must not show another tenant's trip.");
    }
}
