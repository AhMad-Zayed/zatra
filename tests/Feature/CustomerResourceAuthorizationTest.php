<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Emergency hotfix, same tier as the storefront security fixes earlier this session:
 * app/Policies/ had a policy for every other tenant-facing resource (Booking, Payment, Hotel,
 * RoomType, TripInstance, TripTemplate, Activity, Role, TripStayLeg/TripStayLegHotelOption) --
 * but no CustomerPolicy. Filament's authorization defaults to allow when a model has no policy
 * at all, so any tenant-attached User, regardless of role or permissions, could view every
 * customer's real name and phone number at /admin/{tenant}/customers, while the structurally
 * identical /admin/{tenant}/bookings correctly 403'd for the same user. Confirmed live (see the
 * CustomerPolicy-added/removed toggle used while developing this fix): removing the new policy
 * reproduces 200 + the real customer's name present in the response; restoring it reproduces 403,
 * exactly matching the already-correct booking behavior.
 */
class CustomerResourceAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function makeRolelessUser(Tenant $tenant): User
    {
        $user = User::create(['name' => 'Roleless Staff', 'phone' => '0790000001']);
        $user->tenants()->attach($tenant);

        return $user;
    }

    public function test_roleless_tenant_user_cannot_list_customers(): void
    {
        $tenant = Tenant::create(['name' => 'Agency']);
        $user = $this->makeRolelessUser($tenant);
        Customer::create(['tenant_id' => $tenant->id, 'name' => 'Real Secret Customer Name', 'phone' => '0799999999']);

        $this->actingAs($user);

        $response = $this->get("/admin/{$tenant->id}/customers");

        $response->assertForbidden();
        $response->assertDontSee('Real Secret Customer Name');
        $response->assertDontSee('0799999999');
    }

    public function test_roleless_tenant_user_cannot_view_a_single_customer(): void
    {
        $tenant = Tenant::create(['name' => 'Agency']);
        $user = $this->makeRolelessUser($tenant);
        $customer = Customer::create(['tenant_id' => $tenant->id, 'name' => 'Real Secret Customer Name', 'phone' => '0799999999']);

        $this->actingAs($user);

        $response = $this->get("/admin/{$tenant->id}/customers/{$customer->id}");

        $response->assertForbidden();
    }

    /**
     * The same roleless-user scenario against the resource this policy is modeled on, in the
     * same test run, to prove the fix actually brings CustomerResource in line with
     * BookingResource's existing behavior rather than just asserting a number independently.
     */
    public function test_roleless_tenant_user_gets_the_same_403_on_bookings_as_customers(): void
    {
        $tenant = Tenant::create(['name' => 'Agency']);
        $user = $this->makeRolelessUser($tenant);

        $this->actingAs($user);

        $this->get("/admin/{$tenant->id}/customers")->assertForbidden();
        $this->get("/admin/{$tenant->id}/bookings")->assertForbidden();
    }

    public function test_agency_admin_can_still_list_and_view_customers(): void
    {
        $tenant = Tenant::create(['name' => 'Agency']);
        $admin = User::create(['name' => 'Agency Admin', 'phone' => '0790000002']);
        $admin->tenants()->attach($tenant);
        Role::firstOrCreate(['name' => 'agency_admin']);
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $admin->assignRole('agency_admin');

        $customer = Customer::create(['tenant_id' => $tenant->id, 'name' => 'Visible Customer', 'phone' => '0798888888']);

        $this->actingAs($admin);

        $this->get("/admin/{$tenant->id}/customers")
            ->assertOk()
            ->assertSee('Visible Customer');

        $this->get("/admin/{$tenant->id}/customers/{$customer->id}")->assertOk();
    }
}
