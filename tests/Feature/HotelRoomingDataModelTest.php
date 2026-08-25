<?php

namespace Tests\Feature;

use App\Filament\Resources\HotelResource\Pages\CreateHotel;
use App\Filament\Resources\HotelResource\Pages\EditHotel;
use App\Filament\Resources\HotelResource\Pages\ListHotels;
use App\Filament\Resources\TripInstanceResource\Pages\EditTripInstance;
use App\Filament\Resources\TripInstanceResource\RelationManagers\TripStayLegsRelationManager;
use App\Models\Hotel;
use App\Models\PackageOption;
use App\Models\RoomType;
use App\Models\Tenant;
use App\Models\TripInstance;
use App\Models\TripStayLeg;
use App\Models\TripStayLegHotelOption;
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
 * Regression coverage for Hotel/Rooming redesign Ticket 1 (data model + admin CRUD only, zero
 * booking integration). PackageOption and every one of its live call sites are deliberately
 * untouched by this ticket — see the separate confirmation tests at the bottom of this file.
 */
class HotelRoomingDataModelTest extends TestCase
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
     * @return array{tenant: Tenant, admin: User, template: TripTemplate, instance: TripInstance}
     */
    private function makeFixture(string $suffix): array
    {
        $tenant = Tenant::create(['name' => "Agency {$suffix}", 'slug' => "agency-hrm-{$suffix}", 'domain' => "{$suffix}.zatara.com"]);
        $admin = $this->makeAgencyAdmin($tenant, "07911{$suffix}");
        $template = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'Tour', 'base_price' => 100]);
        $instance = TripInstance::create([
            'tenant_id' => $tenant->id,
            'trip_template_id' => $template->id,
            'start_date' => now()->addDays(5),
            'end_date' => now()->addDays(10),
            'available_seats' => 10,
            'status' => 'active',
        ]);

        return compact('tenant', 'admin', 'template', 'instance');
    }

    // ------------------------------------------------------------------
    // Model relations
    // ------------------------------------------------------------------

    public function test_full_hierarchy_relations_resolve_correctly(): void
    {
        $f = $this->makeFixture('001');

        $hotel = Hotel::create(['tenant_id' => $f['tenant']->id, 'name' => 'Grand Hotel', 'city' => 'Istanbul', 'star_rating' => 5]);

        $leg2 = TripStayLeg::create(['tenant_id' => $f['tenant']->id, 'trip_instance_id' => $f['instance']->id, 'sequence' => 2, 'label' => 'Antalya', 'start_date' => now()->addDays(7), 'end_date' => now()->addDays(10)]);
        $leg1 = TripStayLeg::create(['tenant_id' => $f['tenant']->id, 'trip_instance_id' => $f['instance']->id, 'sequence' => 1, 'label' => 'Istanbul', 'start_date' => now()->addDays(5), 'end_date' => now()->addDays(7)]);

        $option = TripStayLegHotelOption::create(['tenant_id' => $f['tenant']->id, 'trip_stay_leg_id' => $leg1->id, 'hotel_id' => $hotel->id, 'label' => 'Standard', 'meal_plan' => 'BB']);

        $roomType = RoomType::create([
            'tenant_id' => $f['tenant']->id,
            'trip_stay_leg_hotel_option_id' => $option->id,
            'name' => 'Double', 'capacity_per_room' => 2, 'room_count' => 10,
            'price_adjustment_shared' => 50.00, 'price_adjustment_single_supplement' => 30.00,
        ]);

        // TripInstance -> legs, ordered by sequence regardless of creation order.
        $this->assertEquals(['Istanbul', 'Antalya'], $f['instance']->fresh()->tripStayLegs->pluck('label')->toArray());

        // Leg -> hotel options -> hotel.
        $this->assertTrue($leg1->hotelOptions->contains($option));
        $this->assertEquals($hotel->id, $option->hotel->id);
        $this->assertEquals('Grand Hotel', $option->fresh()->hotel->name);

        // Hotel -> hotel options (reverse).
        $this->assertTrue($hotel->tripStayLegHotelOptions->contains($option));

        // Hotel option -> room types.
        $this->assertTrue($option->roomTypes->contains($roomType));
        $this->assertEquals(10, $roomType->room_count);
        $this->assertEquals(2, $roomType->capacity_per_room);

        // MoneyCast applied correctly (stored as cents, read back as major units).
        $this->assertEquals(50.00, $roomType->price_adjustment_shared);
        $this->assertEquals(30.00, $roomType->price_adjustment_single_supplement);
        $this->assertEquals(5000, $roomType->getRawOriginal('price_adjustment_shared'));

        // leg2 has zero hotel options — a leg without accommodation configured yet is valid.
        $this->assertCount(0, $leg2->hotelOptions);
    }

    public function test_hotel_options_and_room_types_are_ordered_by_sort_order(): void
    {
        $f = $this->makeFixture('002');
        $hotel = Hotel::create(['tenant_id' => $f['tenant']->id, 'name' => 'H1']);
        $leg = TripStayLeg::create(['tenant_id' => $f['tenant']->id, 'trip_instance_id' => $f['instance']->id, 'sequence' => 1, 'start_date' => now(), 'end_date' => now()->addDay()]);

        $optionB = TripStayLegHotelOption::create(['tenant_id' => $f['tenant']->id, 'trip_stay_leg_id' => $leg->id, 'hotel_id' => $hotel->id, 'label' => 'B', 'sort_order' => 2]);
        $optionA = TripStayLegHotelOption::create(['tenant_id' => $f['tenant']->id, 'trip_stay_leg_id' => $leg->id, 'hotel_id' => $hotel->id, 'label' => 'A', 'sort_order' => 1]);

        $this->assertEquals(['A', 'B'], $leg->fresh()->hotelOptions->pluck('label')->toArray());

        RoomType::create(['tenant_id' => $f['tenant']->id, 'trip_stay_leg_hotel_option_id' => $optionA->id, 'name' => 'Z', 'capacity_per_room' => 2, 'room_count' => 1, 'sort_order' => 2]);
        RoomType::create(['tenant_id' => $f['tenant']->id, 'trip_stay_leg_hotel_option_id' => $optionA->id, 'name' => 'Y', 'capacity_per_room' => 1, 'room_count' => 1, 'sort_order' => 1]);

        $this->assertEquals(['Y', 'Z'], $optionA->fresh()->roomTypes->pluck('name')->toArray());
    }

    // ------------------------------------------------------------------
    // Tenant isolation
    // ------------------------------------------------------------------

    public function test_hotel_resource_scopes_to_current_tenant_only(): void
    {
        $fA = $this->makeFixture('003a');
        $fB = $this->makeFixture('003b');

        $hotelA = Hotel::create(['tenant_id' => $fA['tenant']->id, 'name' => 'Tenant A Hotel']);
        $hotelB = Hotel::create(['tenant_id' => $fB['tenant']->id, 'name' => 'Tenant B Hotel']);

        // makeFixture('003b') left the global Spatie permission team context pointed at tenant
        // B (set during that fixture's own admin setup) — restore it to tenant A's before acting
        // as tenant A's admin, exactly as the real ApplyTenantScopes middleware would on a live
        // request for tenant A's URL.
        setPermissionsTeamId($fA['tenant']->id);
        $this->actingAs($fA['admin']);
        Filament::setTenant($fA['tenant'], true);

        Livewire::test(ListHotels::class)
            ->assertCanSeeTableRecords([$hotelA])
            ->assertCanNotSeeTableRecords([$hotelB]);
    }

    public function test_a_tenant_cannot_edit_another_tenants_hotel_via_direct_url(): void
    {
        $fA = $this->makeFixture('004a');
        $fB = $this->makeFixture('004b');

        $hotelB = Hotel::create(['tenant_id' => $fB['tenant']->id, 'name' => 'Tenant B Hotel']);

        $this->actingAs($fA['admin']);
        Filament::setTenant($fA['tenant'], true);

        $this->get("/admin/{$fA['tenant']->id}/hotels/{$hotelB->id}/edit")->assertNotFound();
    }

    // ------------------------------------------------------------------
    // Hotel CRUD
    // ------------------------------------------------------------------

    public function test_hotel_can_be_created_through_the_resource_and_is_tenant_scoped_automatically(): void
    {
        $f = $this->makeFixture('005');
        $this->actingAs($f['admin']);
        Filament::setTenant($f['tenant'], true);

        Livewire::test(CreateHotel::class)
            ->fillForm([
                'name' => 'Marriott Aqaba',
                'city' => 'Aqaba',
                'star_rating' => 5,
                'is_active' => true,
                'contact_phone' => '+962700000000',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $hotel = Hotel::where('name', 'Marriott Aqaba')->first();
        $this->assertNotNull($hotel);
        $this->assertEquals($f['tenant']->id, $hotel->tenant_id, 'tenant_id must be auto-assigned by Filament\'s native panel tenancy, matching TripTemplate/PackageOption\'s own pattern.');
        $this->assertEquals(5, $hotel->star_rating);
    }

    public function test_hotel_can_be_edited(): void
    {
        $f = $this->makeFixture('006');
        $hotel = Hotel::create(['tenant_id' => $f['tenant']->id, 'name' => 'Old Name', 'is_active' => true]);

        $this->actingAs($f['admin']);
        Filament::setTenant($f['tenant'], true);

        Livewire::test(EditHotel::class, ['record' => $hotel->getRouteKey()])
            ->fillForm(['name' => 'New Name'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEquals('New Name', $hotel->fresh()->name);
    }

    public function test_hotel_deletion_is_blocked_while_referenced_by_a_trip_stay_leg_hotel_option(): void
    {
        $f = $this->makeFixture('007');
        $hotel = Hotel::create(['tenant_id' => $f['tenant']->id, 'name' => 'Referenced Hotel']);
        $leg = TripStayLeg::create(['tenant_id' => $f['tenant']->id, 'trip_instance_id' => $f['instance']->id, 'sequence' => 1, 'start_date' => now(), 'end_date' => now()->addDay()]);
        TripStayLegHotelOption::create(['tenant_id' => $f['tenant']->id, 'trip_stay_leg_id' => $leg->id, 'hotel_id' => $hotel->id]);

        $this->actingAs($f['admin']);
        Filament::setTenant($f['tenant'], true);

        Livewire::test(ListHotels::class)->callTableAction('delete', $hotel);

        $this->assertNotNull($hotel->fresh(), 'A referenced hotel must not be deleted — the application-level guard must cancel the action.');
    }

    public function test_hotel_deletion_succeeds_when_unreferenced(): void
    {
        $f = $this->makeFixture('008');
        $hotel = Hotel::create(['tenant_id' => $f['tenant']->id, 'name' => 'Unused Hotel']);

        $this->actingAs($f['admin']);
        Filament::setTenant($f['tenant'], true);

        Livewire::test(ListHotels::class)->callTableAction('delete', $hotel);

        $this->assertSoftDeleted($hotel);
    }

    public function test_hard_deleting_a_referenced_hotel_is_blocked_at_the_database_level(): void
    {
        // Backstop test for the restrictOnDelete() FK constraint itself, independent of the
        // application-level guard above (e.g. if a future code path bypasses the Resource UI).
        $f = $this->makeFixture('009');
        $hotel = Hotel::create(['tenant_id' => $f['tenant']->id, 'name' => 'Hard Delete Test']);
        $leg = TripStayLeg::create(['tenant_id' => $f['tenant']->id, 'trip_instance_id' => $f['instance']->id, 'sequence' => 1, 'start_date' => now(), 'end_date' => now()->addDay()]);
        TripStayLegHotelOption::create(['tenant_id' => $f['tenant']->id, 'trip_stay_leg_id' => $leg->id, 'hotel_id' => $hotel->id]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        \Illuminate\Support\Facades\DB::table('hotels')->where('id', $hotel->id)->delete();
    }

    // ------------------------------------------------------------------
    // Cascade behavior on delete
    // ------------------------------------------------------------------

    public function test_hard_deleting_a_trip_stay_leg_cascades_to_hotel_options_and_room_types(): void
    {
        $f = $this->makeFixture('010');
        $hotel = Hotel::create(['tenant_id' => $f['tenant']->id, 'name' => 'H']);
        $leg = TripStayLeg::create(['tenant_id' => $f['tenant']->id, 'trip_instance_id' => $f['instance']->id, 'sequence' => 1, 'start_date' => now(), 'end_date' => now()->addDay()]);
        $option = TripStayLegHotelOption::create(['tenant_id' => $f['tenant']->id, 'trip_stay_leg_id' => $leg->id, 'hotel_id' => $hotel->id]);
        $roomType = RoomType::create(['tenant_id' => $f['tenant']->id, 'trip_stay_leg_hotel_option_id' => $option->id, 'name' => 'Double', 'capacity_per_room' => 2, 'room_count' => 5]);

        \Illuminate\Support\Facades\DB::table('trip_stay_legs')->where('id', $leg->id)->delete();

        $this->assertDatabaseMissing('trip_stay_leg_hotel_options', ['id' => $option->id]);
        $this->assertDatabaseMissing('room_types', ['id' => $roomType->id]);
        // The Hotel master record itself must survive — only the trip-specific option/room-type
        // rows that referenced it are gone.
        $this->assertNotNull($hotel->fresh());
    }

    public function test_hard_deleting_a_hotel_option_cascades_to_room_types_only(): void
    {
        $f = $this->makeFixture('011');
        $hotel = Hotel::create(['tenant_id' => $f['tenant']->id, 'name' => 'H']);
        $leg = TripStayLeg::create(['tenant_id' => $f['tenant']->id, 'trip_instance_id' => $f['instance']->id, 'sequence' => 1, 'start_date' => now(), 'end_date' => now()->addDay()]);
        $option = TripStayLegHotelOption::create(['tenant_id' => $f['tenant']->id, 'trip_stay_leg_id' => $leg->id, 'hotel_id' => $hotel->id]);
        $roomType = RoomType::create(['tenant_id' => $f['tenant']->id, 'trip_stay_leg_hotel_option_id' => $option->id, 'name' => 'Double', 'capacity_per_room' => 2, 'room_count' => 5]);

        \Illuminate\Support\Facades\DB::table('trip_stay_leg_hotel_options')->where('id', $option->id)->delete();

        $this->assertDatabaseMissing('room_types', ['id' => $roomType->id]);
        $this->assertNotNull($leg->fresh(), 'The leg itself must survive — deleting one hotel option must not delete its parent leg.');
    }

    public function test_soft_deleting_via_filament_does_not_hard_delete_children(): void
    {
        // Documents the known, pre-existing app-wide behavior (matches TripInstance's own
        // relationship to TripPassengerCategory/TripAddon): SoftDeletes on the parent does NOT
        // trigger the DB cascadeOnDelete() FK, since a soft delete is just an UPDATE, not a real
        // DELETE. Children remain individually queryable unless separately soft-deleted. Not a
        // new gap introduced by this ticket — flagged, not fixed, consistent with the rest of
        // this app's existing behavior for identical parent/child shapes.
        $f = $this->makeFixture('012');
        $hotel = Hotel::create(['tenant_id' => $f['tenant']->id, 'name' => 'H']);
        $leg = TripStayLeg::create(['tenant_id' => $f['tenant']->id, 'trip_instance_id' => $f['instance']->id, 'sequence' => 1, 'start_date' => now(), 'end_date' => now()->addDay()]);
        $option = TripStayLegHotelOption::create(['tenant_id' => $f['tenant']->id, 'trip_stay_leg_id' => $leg->id, 'hotel_id' => $hotel->id]);

        $leg->delete(); // Eloquent soft delete

        $this->assertSoftDeleted($leg);
        $this->assertNull($option->fresh()->deleted_at, 'The child\'s own deleted_at is untouched by the parent\'s soft delete...');
        $this->assertNotNull(TripStayLegHotelOption::find($option->id), '...so it remains fully findable/active on its own, even though its parent leg is now soft-deleted. This mirrors the identical, pre-existing behavior for TripInstance -> TripPassengerCategory/TripAddon elsewhere in this app — not a new gap introduced here.');
    }

    // ------------------------------------------------------------------
    // TripStayLegsRelationManager CRUD (nested repeater-relationship form)
    // ------------------------------------------------------------------

    public function test_leg_can_be_created_via_relation_manager_and_is_tenant_scoped(): void
    {
        $f = $this->makeFixture('013');
        $this->actingAs($f['admin']);
        Filament::setTenant($f['tenant'], true);

        Livewire::test(TripStayLegsRelationManager::class, [
            'ownerRecord' => $f['instance'],
            'pageClass' => EditTripInstance::class,
        ])->callTableAction('create', data: [
            'sequence' => 1,
            'label' => 'Istanbul',
            'start_date' => now()->addDays(5)->toDateString(),
            'end_date' => now()->addDays(7)->toDateString(),
        ]);

        $leg = TripStayLeg::where('trip_instance_id', $f['instance']->id)->first();
        $this->assertNotNull($leg);
        $this->assertEquals($f['tenant']->id, $leg->tenant_id);
        $this->assertEquals('Istanbul', $leg->label);
    }

    public function test_leg_with_nested_hotel_option_and_room_type_can_be_created_in_one_form(): void
    {
        $f = $this->makeFixture('014');
        $hotel = Hotel::create(['tenant_id' => $f['tenant']->id, 'name' => 'Nested Test Hotel', 'is_active' => true]);
        $this->actingAs($f['admin']);
        Filament::setTenant($f['tenant'], true);

        Livewire::test(TripStayLegsRelationManager::class, [
            'ownerRecord' => $f['instance'],
            'pageClass' => EditTripInstance::class,
        ])->callTableAction('create', data: [
            'sequence' => 1,
            'label' => 'Antalya',
            'start_date' => now()->addDays(7)->toDateString(),
            'end_date' => now()->addDays(11)->toDateString(),
            'hotelOptions' => [
                [
                    'hotel_id' => $hotel->id,
                    'label' => 'Standard',
                    'meal_plan' => 'All Inclusive',
                    'is_active' => true,
                    'sort_order' => 0,
                    'roomTypes' => [
                        [
                            'name' => 'Double',
                            'capacity_per_room' => 2,
                            'room_count' => 15,
                            'price_adjustment_shared' => 40,
                            'price_adjustment_single_supplement' => 25,
                            'is_active' => true,
                            'sort_order' => 0,
                        ],
                    ],
                ],
            ],
        ]);

        $leg = TripStayLeg::where('trip_instance_id', $f['instance']->id)->where('label', 'Antalya')->first();
        $this->assertNotNull($leg);

        $option = $leg->hotelOptions()->first();
        $this->assertNotNull($option, 'Nested hotelOptions repeater must persist.');
        $this->assertEquals($hotel->id, $option->hotel_id);
        $this->assertEquals($f['tenant']->id, $option->tenant_id);

        $roomType = $option->roomTypes()->first();
        $this->assertNotNull($roomType, 'Doubly-nested roomTypes repeater must persist.');
        $this->assertEquals(15, $roomType->room_count);
        $this->assertEquals(2, $roomType->capacity_per_room);
        $this->assertEquals(40.00, $roomType->price_adjustment_shared);
        $this->assertEquals(25.00, $roomType->price_adjustment_single_supplement);
        $this->assertEquals($f['tenant']->id, $roomType->tenant_id);
    }

    // ------------------------------------------------------------------
    // PackageOption remains fully untouched (14 call sites confirmed in the investigation)
    // ------------------------------------------------------------------

    public function test_package_option_model_and_relations_are_unaffected(): void
    {
        $f = $this->makeFixture('015');
        $package = PackageOption::create([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'name' => 'Untouched Package',
            'hotel_name' => 'Old Style Hotel',
            'price_adjustment' => 100,
            'available_seats' => 5,
        ]);

        $this->assertEquals($f['instance']->id, $package->tripInstance->id);
        $this->assertTrue($f['instance']->fresh()->packageOptions->contains($package));
        $this->assertEquals(5, $package->fresh()->remaining_seats);
    }
}
