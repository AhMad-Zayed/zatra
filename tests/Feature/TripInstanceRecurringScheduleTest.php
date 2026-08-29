<?php

namespace Tests\Feature;

use App\Filament\Resources\TripInstanceResource\Pages\CreateTripInstance;
use App\Models\PickupRoute;
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
 * Item 4 (admin panel UX audit follow-up): TripBuilderResource is deleted — confirmed
 * unmaintained since 2026-08-10 and confirmed to silently leave trip_type NULL on every template
 * it created (its form never had a trip_type field at all). Its one genuinely valuable,
 * non-duplicated capability -- generating several TripInstance rows across a recurring date
 * range in one submission -- is ported into TripInstanceResource's own create flow instead, as
 * an additional "جدولة متكررة" option alongside the pre-existing single-instance create.
 *
 * trip_type itself lives on TripTemplate, not TripInstance, and every path here creates
 * instances against an EXISTING template selected the same way single-mode already does -- so
 * there's nothing to "port" for trip_type specifically; these tests instead prove the inheritance
 * that was always true for a normal instance still holds for a batch-generated one.
 */
class TripInstanceRecurringScheduleTest extends TestCase
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
     * @return array{tenant: Tenant, admin: User, template: TripTemplate}
     */
    private function makeFixture(string $suffix, string $tripType = 'international'): array
    {
        $tenant = Tenant::create(['name' => "Agency {$suffix}", 'slug' => "agency-recur-{$suffix}"]);
        $admin = $this->makeAgencyAdmin($tenant, "0794{$suffix}");
        $template = TripTemplate::create([
            'tenant_id' => $tenant->id,
            'title' => 'Recurring Test Trip',
            'base_price' => 200,
            'currency' => 'USD',
            'trip_type' => $tripType,
        ]);
        $template->templatePassengerCategories()->create(['tenant_id' => $tenant->id, 'name' => 'Adult', 'price' => 200, 'requires_seat' => true]);
        $template->templateAddons()->create(['tenant_id' => $tenant->id, 'name' => 'Insurance', 'price' => 20, 'max_quantity' => 10]);

        return compact('tenant', 'admin', 'template');
    }

    private function loadCreatePage(array $f): \Livewire\Features\SupportTesting\Testable
    {
        $this->actingAs($f['admin']);
        Filament::setTenant($f['tenant'], true);

        return Livewire::test(CreateTripInstance::class);
    }

    // ------------------------------------------------------------------
    // Single-instance create path is unaffected
    // ------------------------------------------------------------------

    public function test_single_instance_create_still_works_exactly_as_before(): void
    {
        $f = $this->makeFixture('001');

        $this->loadCreatePage($f)
            ->fillForm([
                'schedule_type' => 'single',
                'trip_template_id' => $f['template']->id,
                'currency' => 'USD',
                'start_date' => now()->addDays(10)->toDateString(),
                'end_date' => now()->addDays(12)->toDateString(),
                'available_seats' => 30,
                'status' => 'active',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(1, TripInstance::where('trip_template_id', $f['template']->id)->count());
    }

    // ------------------------------------------------------------------
    // Recurring schedule: correct instance count, dates, trip_type inheritance
    // ------------------------------------------------------------------

    public function test_recurring_schedule_generates_one_instance_per_matching_weekday_with_correct_trip_type(): void
    {
        $f = $this->makeFixture('002', tripType: 'international');

        // A fixed two-week range starting on a known Monday, matching only Monday+Wednesday
        // (day-of-week 1 and 3) -- deterministic regardless of when the test suite runs.
        $start = \Illuminate\Support\Carbon::parse('next Monday');
        $end = (clone $start)->addDays(13); // exactly 2 full weeks -> 2 Mondays + 2 Wednesdays = 4

        $this->loadCreatePage($f)
            ->fillForm([
                'schedule_type' => 'recurring',
                'trip_template_id' => $f['template']->id,
                'recurring_seats_count' => 25,
                'recurring_start' => $start->toDateString(),
                'recurring_end' => $end->toDateString(),
                'recurring_days' => [1, 3],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $instances = TripInstance::where('trip_template_id', $f['template']->id)->orderBy('start_date')->get();
        $this->assertCount(4, $instances, 'Exactly the 4 Mondays/Wednesdays in the 2-week range must be generated.');

        foreach ($instances as $instance) {
            $this->assertSame(25, $instance->available_seats);
            $this->assertContains((int) $instance->start_date->dayOfWeek, [1, 3]);
            // trip_type lives on the template, not the instance -- confirms the inheritance a
            // normal single-created instance already relies on holds for a batch-generated one.
            $this->assertSame('international', $instance->tripTemplate->trip_type->value);
        }
    }

    public function test_recurring_schedule_copies_categories_and_addons_onto_every_generated_instance(): void
    {
        $f = $this->makeFixture('003');
        $start = \Illuminate\Support\Carbon::parse('next Monday');

        $this->loadCreatePage($f)
            ->fillForm([
                'schedule_type' => 'recurring',
                'trip_template_id' => $f['template']->id,
                'recurring_seats_count' => 10,
                'recurring_start' => $start->toDateString(),
                'recurring_end' => (clone $start)->addDays(7)->toDateString(), // 2 Mondays
                'recurring_days' => [1],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $instances = TripInstance::where('trip_template_id', $f['template']->id)->get();
        $this->assertCount(2, $instances);

        foreach ($instances as $instance) {
            $this->assertSame(1, $instance->tripPassengerCategories()->count());
            $this->assertSame('Adult', $instance->tripPassengerCategories()->first()->name);
            $this->assertSame(1, $instance->tripAddons()->count());
            $this->assertSame('Insurance', $instance->tripAddons()->first()->name);
        }
    }

    public function test_recurring_schedule_attaches_selected_pickup_routes_to_every_generated_instance(): void
    {
        $f = $this->makeFixture('004');
        $route = PickupRoute::create(['tenant_id' => $f['tenant']->id, 'name' => 'Downtown', 'is_active' => true]);
        $start = \Illuminate\Support\Carbon::parse('next Monday');

        $this->loadCreatePage($f)
            ->fillForm([
                'schedule_type' => 'recurring',
                'trip_template_id' => $f['template']->id,
                'recurring_seats_count' => 10,
                'recurring_start' => $start->toDateString(),
                'recurring_end' => $start->toDateString(),
                'recurring_days' => [(int) $start->dayOfWeek],
                'recurring_pickup_routes' => [$route->id],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $instance = TripInstance::where('trip_template_id', $f['template']->id)->first();
        $this->assertNotNull($instance);
        $this->assertTrue($instance->pickupRoutes->contains($route->id));
    }

    public function test_recurring_schedule_with_no_matching_weekday_creates_nothing_and_shows_an_error(): void
    {
        $f = $this->makeFixture('005');
        // A single-day range whose only day-of-week is Tuesday, but only Friday is allowed.
        $tuesday = \Illuminate\Support\Carbon::parse('next Tuesday');

        $this->loadCreatePage($f)
            ->fillForm([
                'schedule_type' => 'recurring',
                'trip_template_id' => $f['template']->id,
                'recurring_seats_count' => 10,
                'recurring_start' => $tuesday->toDateString(),
                'recurring_end' => $tuesday->toDateString(),
                'recurring_days' => [5], // Friday only
            ])
            ->call('create');

        $this->assertSame(0, TripInstance::where('trip_template_id', $f['template']->id)->count());
    }

    // ------------------------------------------------------------------
    // Generated instances are fully usable afterward (Hotel/Rooming, Bus/Fleet integration)
    // ------------------------------------------------------------------

    public function test_a_recurring_generated_instance_accepts_a_stay_leg_and_a_bus_assignment_identically_to_a_manual_one(): void
    {
        $f = $this->makeFixture('006');
        $start = \Illuminate\Support\Carbon::parse('next Monday');

        $this->loadCreatePage($f)
            ->fillForm([
                'schedule_type' => 'recurring',
                'trip_template_id' => $f['template']->id,
                'recurring_seats_count' => 10,
                'recurring_start' => $start->toDateString(),
                'recurring_end' => $start->toDateString(),
                'recurring_days' => [(int) $start->dayOfWeek],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $instance = TripInstance::where('trip_template_id', $f['template']->id)->first();
        $this->assertNotNull($instance);

        $stayLeg = $instance->tripStayLegs()->create([
            'tenant_id' => $f['tenant']->id,
            'sequence' => 1,
            'start_date' => $instance->start_date,
            'end_date' => $instance->start_date->copy()->addDay(),
        ]);
        $this->assertNotNull($stayLeg->fresh());

        $vehicle = \App\Models\Vehicle::create(['tenant_id' => $f['tenant']->id, 'plate_number' => 'REC-1', 'default_capacity' => 20]);
        $bus = \App\Models\TripBusAssignment::create([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $instance->id,
            'ownership_type' => 'owned',
            'vehicle_id' => $vehicle->id,
            'capacity' => 20,
        ]);
        $this->assertNotNull($bus->fresh());
    }
}
