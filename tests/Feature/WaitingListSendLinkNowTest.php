<?php

namespace Tests\Feature;

use App\Enums\WaitingListStatusEnum;
use App\Filament\Resources\TripInstanceResource\Pages\EditTripInstance;
use App\Filament\Resources\TripInstanceResource\RelationManagers\WaitingListsRelationManager;
use App\Models\InventoryLedger;
use App\Models\Tenant;
use App\Models\TripInstance;
use App\Models\TripTemplate;
use App\Models\User;
use App\Models\WaitingList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Regression coverage for the `send_link_now` (VIP waitlist override) enum fix.
 *
 * Prior to the fix, InventoryLedger::create() used the invalid DB enum value
 * 'waitlist_hold' (the real column only allows initial_stock/hold/confirmed/
 * cancelled/expired), and the action resolved its TripInstance via the
 * long-dropped `trip_instance_id` column on WaitingList instead of the
 * belongsToMany pivot — both of which made the action fail every time it
 * was invoked.
 */
class WaitingListSendLinkNowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RoleAndPermissionSeeder::class);

        Role::firstOrCreate(['name' => 'panel_user']);
        Permission::firstOrCreate(['name' => 'view_any_trip::instance']);
        Permission::firstOrCreate(['name' => 'view_trip::instance']);
        Permission::firstOrCreate(['name' => 'update_trip::instance']);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @return array{tenant: Tenant, user: User, instance: TripInstance, waitingList: WaitingList}
     */
    private function makeFixture(int $availableSeats): array
    {
        $tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency-wl', 'domain' => 'wl.zatara.com']);

        $user = User::create(['name' => 'Agent', 'phone' => '0791230000']);
        $user->tenants()->attach($tenant);
        setPermissionsTeamId($tenant->id);
        $user->assignRole(['booking_agent', 'panel_user']);
        $user->givePermissionTo(['view_any_trip::instance', 'view_trip::instance', 'update_trip::instance']);

        $template = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'Tour', 'base_price' => 100]);

        $instance = TripInstance::create([
            'tenant_id' => $tenant->id,
            'trip_template_id' => $template->id,
            'start_date' => now()->addDays(5),
            'end_date' => now()->addDays(10),
            'available_seats' => $availableSeats,
            'status' => 'active',
        ]);

        $waitingList = WaitingList::create([
            'tenant_id' => $tenant->id,
            'customer_name' => 'Jane VIP',
            'phone_number' => '0790000001',
            'customer_email' => 'jane@example.com',
            'status' => WaitingListStatusEnum::Pending->value,
        ]);

        $instance->waitingLists()->attach($waitingList->id);

        return compact('tenant', 'user', 'instance', 'waitingList');
    }

    public function test_send_link_now_creates_valid_hold_and_notifies_customer(): void
    {
        ['tenant' => $tenant, 'user' => $user, 'instance' => $instance, 'waitingList' => $waitingList] = $this->makeFixture(5);

        $this->actingAs($user);
        $this->withoutExceptionHandling();

        $this->get("/admin/{$tenant->id}/trip-instances/{$instance->id}/edit")->assertSuccessful();

        Livewire::test(WaitingListsRelationManager::class, [
            'ownerRecord' => $instance,
            'pageClass' => EditTripInstance::class,
        ])->callTableAction('send_link_now', $waitingList);

        // Valid DB enum value, correct trip instance, correct quantity.
        $this->assertDatabaseHas('inventory_ledgers', [
            'trip_instance_id' => $instance->id,
            'type' => 'hold',
            'quantity' => -1,
        ]);
        $this->assertDatabaseMissing('inventory_ledgers', [
            'type' => 'waitlist_hold',
        ]);

        $ledger = InventoryLedger::where('trip_instance_id', $instance->id)->first();
        $this->assertNotNull($ledger->expires_at);
        $this->assertTrue($ledger->expires_at->between(now()->addMinutes(115), now()->addMinutes(125)));

        // Remaining seats reflect the new hold immediately.
        $this->assertSame(4, $instance->fresh()->getRemainingSeatsAttribute());

        // Waiting list record transitions correctly.
        $waitingList->refresh();
        $this->assertTrue($waitingList->status === WaitingListStatusEnum::Notified);
        $this->assertNotNull($waitingList->notified_at);
    }

    public function test_send_link_now_blocks_when_trip_is_full(): void
    {
        ['tenant' => $tenant, 'user' => $user, 'instance' => $instance, 'waitingList' => $waitingList] = $this->makeFixture(0);

        $this->actingAs($user);
        $this->withoutExceptionHandling();

        $this->get("/admin/{$tenant->id}/trip-instances/{$instance->id}/edit")->assertSuccessful();

        Livewire::test(WaitingListsRelationManager::class, [
            'ownerRecord' => $instance,
            'pageClass' => EditTripInstance::class,
        ])->callTableAction('send_link_now', $waitingList);

        $this->assertDatabaseCount('inventory_ledgers', 0);

        $waitingList->refresh();
        $this->assertTrue($waitingList->status === WaitingListStatusEnum::Pending);
        $this->assertNull($waitingList->notified_at);
    }
}
