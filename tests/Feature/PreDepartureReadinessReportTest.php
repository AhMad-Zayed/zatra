<?php

namespace Tests\Feature;

use App\Filament\Clusters\ReportsCenter\Pages\PreDepartureReadinessReport;
use App\Models\Customer;
use App\Models\RequirementPreset;
use App\Models\Tenant;
use App\Models\TripInstance;
use App\Models\TripPassengerCategory;
use App\Models\TripTemplate;
use App\Models\User;
use App\Services\CreateBookingService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Regression coverage for Reports Center Ticket 1, Report 1 (Pre-Departure Readiness) — the
 * highest-priority report per the stakeholder. Read-only: none of these tests write through
 * any guardrail-protected service beyond the standard CreateBookingService fixture-building
 * calls already used identically across every other report/feature test in this app.
 */
class PreDepartureReadinessReportTest extends TestCase
{
    use RefreshDatabase;

    private CreateBookingService $createBookingService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createBookingService = new CreateBookingService();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

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
     * @return array{tenant: Tenant, admin: User, customer: Customer, template: TripTemplate, preset: RequirementPreset}
     */
    private function makeTenantFixture(string $suffix, ?string $tripType = null): array
    {
        $tenant = Tenant::create(['name' => "Agency {$suffix}", 'slug' => "agency-pdr-{$suffix}", 'domain' => "{$suffix}.zatara.com"]);
        $admin = $this->makeAgencyAdmin($tenant, "0793{$suffix}");
        $customer = Customer::create(['name' => 'Jane', 'phone' => "0592{$suffix}", 'tenant_id' => $tenant->id]);

        $preset = RequirementPreset::create([
            'tenant_id' => $tenant->id,
            'title' => 'Standard',
            'items' => [
                ['name' => 'رقم جواز السفر', 'type' => 'text', 'is_required' => true],
                ['name' => 'تاريخ الميلاد', 'type' => 'date', 'is_required' => true],
                ['name' => 'صورة الجواز', 'type' => 'image', 'is_required' => true],
            ],
        ]);

        $template = TripTemplate::create([
            'tenant_id' => $tenant->id, 'title' => 'Maldives Tour', 'base_price' => 100,
            'requirement_preset_id' => $preset->id,
            'trip_type' => $tripType,
        ]);

        return compact('tenant', 'admin', 'customer', 'template', 'preset');
    }

    /**
     * @return array{instance: TripInstance, cat: TripPassengerCategory}
     */
    private function makeTripInstance(array $f, string $suffix, int $daysFromNow): array
    {
        $instance = TripInstance::create([
            'tenant_id' => $f['tenant']->id,
            'trip_template_id' => $f['template']->id,
            'start_date' => now()->addDays($daysFromNow),
            'end_date' => now()->addDays($daysFromNow + 5),
            'available_seats' => 20,
            'status' => 'active',
        ]);
        $cat = TripPassengerCategory::create([
            'tenant_id' => $f['tenant']->id, 'trip_instance_id' => $instance->id,
            'name' => 'Adult', 'price' => 100, 'requires_seat' => true,
        ]);

        return compact('instance', 'cat');
    }

    private function makeBooking(array $f, array $trip, string $firstName, ?string $documentNumber = 'P123', ?string $dob = '1990-01-01'): \App\Models\Booking
    {
        return $this->createBookingService->execute([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $trip['instance']->id,
            'customer_id' => $f['customer']->id,
            'passengersData' => [[
                'trip_passenger_category_id' => $trip['cat']->id,
                'first_name' => $firstName,
                'last_name' => 'X',
                'document_number' => $documentNumber,
                'date_of_birth' => $dob,
            ]],
        ]);
    }

    private function loadReport(array $f): \Livewire\Features\SupportTesting\Testable
    {
        $this->actingAs($f['admin']);
        Filament::setTenant($f['tenant'], true);

        return Livewire::test(PreDepartureReadinessReport::class);
    }

    // ------------------------------------------------------------------
    // Core visibility: incomplete passengers within the window
    // ------------------------------------------------------------------

    public function test_report_shows_a_passenger_with_incomplete_requirements_within_the_departure_window(): void
    {
        $f = $this->makeTenantFixture('001');
        $trip = $this->makeTripInstance($f, '001', 5);
        $booking = $this->makeBooking($f, $trip, 'Incomplete', documentNumber: null, dob: null);
        $passenger = $booking->passengers()->first();
        $this->assertFalse($passenger->fresh()->requirements_complete, 'Fixture sanity check: CreateBookingService must have flagged this passenger incomplete.');

        $this->loadReport($f)->assertCanSeeTableRecords([$passenger]);
    }

    public function test_report_excludes_a_passenger_with_complete_requirements(): void
    {
        $f = $this->makeTenantFixture('002');
        $trip = $this->makeTripInstance($f, '002', 5);
        $booking = $this->makeBooking($f, $trip, 'Complete', documentNumber: 'P999', dob: '1985-05-05');
        $passenger = $booking->passengers()->first();
        // The 'image' requirement is still outstanding (no upload in this fixture), so this
        // passenger stays incomplete regardless — flip it directly to prove the report reacts
        // to the flag itself, independent of how it got set.
        $passenger->update(['requirements_complete' => true]);

        $this->loadReport($f)->assertCanNotSeeTableRecords([$passenger]);
    }

    /**
     * Regression for the audit's Friction Point #1: Filament's DatePicker, under ->native(false),
     * hydrates a date-only default into a Carbon instance via Carbon::createFromFormat('Y-m-d', ...)
     * -- which fills the unspecified time with the current wall-clock time -- then stringifies it
     * as a full "Y-m-d H:i:s" timestamp. Every report's applyDateRange closure fed that into
     * whereDate($column, '>=', $from), and DATE($column) compared lexicographically against a
     * timestamp string excludes today's own rows at any time other than exactly midnight. Freeze
     * "now" at a realistic evening time (not midnight) specifically so this doesn't accidentally
     * pass by luck of the clock, and assert the passenger is visible under the report's own
     * DEFAULT filter state -- no filterTable() call here, unlike every other filter test above.
     */
    public function test_report_shows_todays_incomplete_passenger_under_the_default_filter_at_a_realistic_time_of_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-29 21:49:30'));

        $f = $this->makeTenantFixture('011');
        $tripToday = $this->makeTripInstance($f, '011', 0);
        $booking = $this->makeBooking($f, $tripToday, 'TodayIncomplete', documentNumber: null, dob: null);
        $passenger = $booking->passengers()->first();
        $this->assertFalse($passenger->fresh()->requirements_complete, 'Fixture sanity check: CreateBookingService must have flagged this passenger incomplete.');

        $this->loadReport($f)->assertCanSeeTableRecords([$passenger]);
    }

    public function test_report_excludes_trips_outside_the_default_departure_window(): void
    {
        $f = $this->makeTenantFixture('003');
        $farTrip = $this->makeTripInstance($f, '003', 30); // outside the default 14-day window
        $booking = $this->makeBooking($f, $farTrip, 'FarFuture', documentNumber: null, dob: null);
        $passenger = $booking->passengers()->first();

        $this->loadReport($f)->assertCanNotSeeTableRecords([$passenger]);
    }

    public function test_report_sorts_soonest_departure_first(): void
    {
        $f = $this->makeTenantFixture('004');
        $laterTrip = $this->makeTripInstance($f, '004a', 10);
        $soonerTrip = $this->makeTripInstance($f, '004b', 2);
        $laterBooking = $this->makeBooking($f, $laterTrip, 'Later', documentNumber: null, dob: null);
        $soonerBooking = $this->makeBooking($f, $soonerTrip, 'Sooner', documentNumber: null, dob: null);

        $component = $this->loadReport($f);
        $records = $component->instance()->getTableRecords();

        $ids = $records->pluck('id')->values()->all();
        $soonerId = $soonerBooking->passengers()->first()->id;
        $laterId = $laterBooking->passengers()->first()->id;

        $this->assertSame(
            [$soonerId, $laterId],
            array_values(array_intersect($ids, [$soonerId, $laterId])),
            'The passenger on the sooner-departing trip must be listed before the later one.'
        );
    }

    // ------------------------------------------------------------------
    // Missing-item detail (reuses RequirementValidationService)
    // ------------------------------------------------------------------

    public function test_missing_items_detail_matches_what_is_actually_missing(): void
    {
        $f = $this->makeTenantFixture('005');
        $trip = $this->makeTripInstance($f, '005', 5);
        // document_number present, date_of_birth missing, no identity document uploaded.
        $booking = $this->makeBooking($f, $trip, 'Partial', documentNumber: 'P111', dob: null);
        $passenger = $booking->passengers()->first();

        $page = new PreDepartureReadinessReport();
        $method = new \ReflectionMethod($page, 'missingItemLabels');
        $method->setAccessible(true);
        $labels = $method->invoke($page, $passenger->fresh()->load('booking.tripInstance.tripTemplate.requirementPreset'));

        $this->assertContains('تاريخ الميلاد', $labels);
        $this->assertContains('صورة الجواز', $labels);
        $this->assertNotContains('رقم جواز السفر', $labels, 'document_number was provided, so that specific item must not show as missing.');
    }

    // ------------------------------------------------------------------
    // Filters
    // ------------------------------------------------------------------

    public function test_date_range_filter_narrows_results(): void
    {
        $f = $this->makeTenantFixture('006');
        $tripSoon = $this->makeTripInstance($f, '006a', 3);
        $tripLater = $this->makeTripInstance($f, '006b', 12);
        $bookingSoon = $this->makeBooking($f, $tripSoon, 'Soon', documentNumber: null, dob: null);
        $bookingLater = $this->makeBooking($f, $tripLater, 'Later', documentNumber: null, dob: null);

        $component = $this->loadReport($f);
        $component->filterTable('date_range', ['date_from' => now()->toDateString(), 'date_to' => now()->addDays(5)->toDateString()]);

        $component->assertCanSeeTableRecords([$bookingSoon->passengers()->first()]);
        $component->assertCanNotSeeTableRecords([$bookingLater->passengers()->first()]);
    }

    public function test_trip_instance_filter_narrows_results(): void
    {
        $f = $this->makeTenantFixture('007');
        $tripA = $this->makeTripInstance($f, '007a', 3);
        $tripB = $this->makeTripInstance($f, '007b', 4);
        $bookingA = $this->makeBooking($f, $tripA, 'A', documentNumber: null, dob: null);
        $bookingB = $this->makeBooking($f, $tripB, 'B', documentNumber: null, dob: null);

        $component = $this->loadReport($f);
        $component->filterTable('trip_instance_id', $tripA['instance']->id);

        $component->assertCanSeeTableRecords([$bookingA->passengers()->first()]);
        $component->assertCanNotSeeTableRecords([$bookingB->passengers()->first()]);
    }

    public function test_trip_type_filter_narrows_results(): void
    {
        $fDomestic = $this->makeTenantFixture('008', tripType: 'domestic');
        // Re-use the same tenant for both templates so the filter test isn't confounded by
        // tenant scoping — attach a second, international template to the same tenant.
        $internationalTemplate = TripTemplate::create([
            'tenant_id' => $fDomestic['tenant']->id, 'title' => 'Intl Tour', 'base_price' => 100,
            'requirement_preset_id' => $fDomestic['preset']->id,
            'trip_type' => 'international',
        ]);
        $fInternational = $fDomestic;
        $fInternational['template'] = $internationalTemplate;

        $tripDomestic = $this->makeTripInstance($fDomestic, '008a', 3);
        $tripIntl = $this->makeTripInstance($fInternational, '008b', 4);
        $bookingDomestic = $this->makeBooking($fDomestic, $tripDomestic, 'Dom', documentNumber: null, dob: null);
        $bookingIntl = $this->makeBooking($fInternational, $tripIntl, 'Intl', documentNumber: null, dob: null);

        $component = $this->loadReport($fDomestic);
        $component->filterTable('trip_type', 'domestic');

        $component->assertCanSeeTableRecords([$bookingDomestic->passengers()->first()]);
        $component->assertCanNotSeeTableRecords([$bookingIntl->passengers()->first()]);
    }

    // ------------------------------------------------------------------
    // Tenant isolation
    // ------------------------------------------------------------------

    public function test_report_does_not_leak_another_tenants_passengers(): void
    {
        $fA = $this->makeTenantFixture('009a');
        $fB = $this->makeTenantFixture('009b');
        $tripA = $this->makeTripInstance($fA, '009a', 3);
        $tripB = $this->makeTripInstance($fB, '009b', 3);
        $bookingA = $this->makeBooking($fA, $tripA, 'A', documentNumber: null, dob: null);
        $bookingB = $this->makeBooking($fB, $tripB, 'B', documentNumber: null, dob: null);

        $this->loadReport($fA)
            ->assertCanSeeTableRecords([$bookingA->passengers()->first()])
            ->assertCanNotSeeTableRecords([$bookingB->passengers()->first()]);
    }

    // ------------------------------------------------------------------
    // Excel export
    // ------------------------------------------------------------------

    public function test_export_action_runs_without_error(): void
    {
        $f = $this->makeTenantFixture('010');
        $trip = $this->makeTripInstance($f, '010', 3);
        $this->makeBooking($f, $trip, 'Exportee', documentNumber: null, dob: null);

        $component = $this->loadReport($f);

        $component->callTableAction('export');

        $component->assertHasNoTableActionErrors();
    }
}
