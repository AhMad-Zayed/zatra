<?php

namespace Tests\Feature;

use App\Enums\WaitingListStatusEnum;
use App\Filament\Clusters\ReportsCenter\Pages\WaitlistCancellationHealthReport;
use App\Filament\Resources\WaitingListResource;
use App\Filament\Resources\WaitingListResource\Pages\CreateWaitingList;
use App\Filament\Support\WaitlistConversionForm;
use App\Models\Customer;
use App\Models\InventoryLedger;
use App\Models\Tenant;
use App\Models\TripInstance;
use App\Models\TripPassengerCategory;
use App\Models\TripTemplate;
use App\Models\User;
use App\Models\WaitingList;
use App\Services\WaitlistConversionService;
use Filament\Facades\Filament;
use Filament\Forms\Form;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Waitlist-to-Different-Trip Manual Transfer: a staff member converts a WaitingList entry
 * directly into a real booking on ANY trip instance (not restricted to the trip(s) it was
 * originally waitlisted for). Covers WaitlistConversionService::convertToBooking() (the new
 * CreateBookingService consumer), the audit trail, Report 4's correct attribution to the
 * ORIGINAL template (not the destination), the stale-hold release, and the two adjacent fixes
 * folded into this ticket (the WaitingListStatusEnum mismatch, ActivityLogResource's new label).
 */
class WaitlistConversionTest extends TestCase
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
     * @return array{tenant: Tenant, admin: User, sourceTemplate: TripTemplate, sourceTrip: TripInstance, destTemplate: TripTemplate, destTrip: TripInstance, destCat: TripPassengerCategory, waitingList: WaitingList}
     */
    private function makeFixture(string $suffix, string $destCurrency = 'USD'): array
    {
        $tenant = Tenant::create(['name' => "Agency {$suffix}", 'slug' => "agency-wlc-{$suffix}"]);
        $admin = $this->makeAgencyAdmin($tenant, "0501{$suffix}");

        $sourceTemplate = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => "Source Route {$suffix}", 'base_price' => 100]);
        $sourceTrip = TripInstance::create([
            'tenant_id' => $tenant->id, 'trip_template_id' => $sourceTemplate->id,
            'start_date' => now()->addDays(5), 'end_date' => now()->addDays(6),
            'available_seats' => 10, 'status' => 'active',
        ]);

        $destTemplate = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => "Destination Route {$suffix}", 'base_price' => 200]);
        $destTrip = TripInstance::create([
            'tenant_id' => $tenant->id, 'trip_template_id' => $destTemplate->id,
            'start_date' => now()->addDays(10), 'end_date' => now()->addDays(11),
            'available_seats' => 10, 'status' => 'active', 'currency' => $destCurrency,
        ]);
        $destCat = TripPassengerCategory::create([
            'tenant_id' => $tenant->id, 'trip_instance_id' => $destTrip->id,
            'name' => 'Adult', 'price' => 150, 'requires_seat' => true,
        ]);

        $waitingList = WaitingList::create([
            'tenant_id' => $tenant->id, 'customer_name' => 'Jane Waiter', 'phone_number' => "0599{$suffix}",
            'customer_email' => "jane{$suffix}@example.com", 'seats_requested' => 2,
            'status' => WaitingListStatusEnum::Pending,
        ]);
        $waitingList->tripInstances()->attach($sourceTrip->id, ['priority' => 1]);

        return compact('tenant', 'admin', 'sourceTemplate', 'sourceTrip', 'destTemplate', 'destTrip', 'destCat', 'waitingList');
    }

    // ------------------------------------------------------------------
    // Core conversion behavior
    // ------------------------------------------------------------------

    public function test_conversion_creates_a_booking_on_the_destination_trip_with_placeholder_passengers(): void
    {
        $f = $this->makeFixture('001');

        $booking = app(WaitlistConversionService::class)->convertToBooking(
            $f['waitingList'],
            $f['destTrip'],
            [['category_id' => $f['destCat']->id, 'count' => 2]],
            $f['admin']->id
        );

        $this->assertSame($f['destTrip']->id, $booking->trip_instance_id);
        $this->assertCount(2, $booking->passengers);
        foreach ($booking->passengers as $passenger) {
            $this->assertNull($passenger->first_name);
            $this->assertFalse((bool) $passenger->data_complete);
            $this->assertSame($f['destCat']->id, $passenger->trip_passenger_category_id);
        }
    }

    public function test_conversion_resolves_the_customer_by_phone_matching_phone_booking_page_pattern(): void
    {
        $f = $this->makeFixture('002');

        $booking = app(WaitlistConversionService::class)->convertToBooking(
            $f['waitingList'], $f['destTrip'], [['category_id' => $f['destCat']->id, 'count' => 1]], $f['admin']->id
        );

        $customer = Customer::find($booking->customer_id);
        $this->assertSame($f['waitingList']->phone_number, $customer->phone);
        $this->assertSame($f['waitingList']->customer_name, $customer->name);

        // Second conversion for the same phone/tenant must reuse the same Customer, not
        // duplicate it.
        $waitingList2 = WaitingList::create([
            'tenant_id' => $f['tenant']->id, 'customer_name' => 'Different Name', 'phone_number' => $f['waitingList']->phone_number,
            'seats_requested' => 1, 'status' => WaitingListStatusEnum::Pending,
        ]);
        $booking2 = app(WaitlistConversionService::class)->convertToBooking(
            $waitingList2, $f['destTrip'], [['category_id' => $f['destCat']->id, 'count' => 1]], $f['admin']->id
        );
        $this->assertSame($customer->id, $booking2->customer_id);
    }

    public function test_conversion_flips_waiting_list_status_to_converted(): void
    {
        $f = $this->makeFixture('003');

        app(WaitlistConversionService::class)->convertToBooking(
            $f['waitingList'], $f['destTrip'], [['category_id' => $f['destCat']->id, 'count' => 2]], $f['admin']->id
        );

        $this->assertSame(WaitingListStatusEnum::Converted, $f['waitingList']->fresh()->status);
    }

    public function test_conversion_does_not_attach_the_destination_trip_to_the_pivot(): void
    {
        $f = $this->makeFixture('004');

        app(WaitlistConversionService::class)->convertToBooking(
            $f['waitingList'], $f['destTrip'], [['category_id' => $f['destCat']->id, 'count' => 2]], $f['admin']->id
        );

        $tripIds = $f['waitingList']->fresh()->tripInstances->pluck('id')->all();
        $this->assertSame([$f['sourceTrip']->id], $tripIds, 'The destination trip must NOT be attached to the pivot -- only the original source trip should remain.');
    }

    public function test_conversion_releases_a_stale_hold_on_the_original_trip(): void
    {
        $f = $this->makeFixture('005');

        $hold = InventoryLedger::create([
            'tenant_id' => $f['tenant']->id, 'trip_instance_id' => $f['sourceTrip']->id,
            'quantity' => -2, 'type' => 'hold', 'expires_at' => now()->addHours(2),
        ]);
        $f['waitingList']->update(['status' => WaitingListStatusEnum::Notified, 'hold_id' => $hold->id]);

        app(WaitlistConversionService::class)->convertToBooking(
            $f['waitingList']->fresh(), $f['destTrip'], [['category_id' => $f['destCat']->id, 'count' => 2]], $f['admin']->id
        );

        $this->assertSame('expired', $hold->fresh()->type, 'The stale hold on the ORIGINAL trip must be released once converted to a different trip.');
    }

    public function test_currency_is_inherited_natively_from_the_destination_trip(): void
    {
        $f = $this->makeFixture('006', destCurrency: 'JOD');

        $booking = app(WaitlistConversionService::class)->convertToBooking(
            $f['waitingList'], $f['destTrip'], [['category_id' => $f['destCat']->id, 'count' => 1]], $f['admin']->id
        );

        $this->assertSame('JOD', $booking->currency, "A brand-new booking with zero prior payments takes the destination trip's currency natively -- nothing to reconcile.");
    }

    public function test_cannot_convert_an_already_converted_entry(): void
    {
        $f = $this->makeFixture('007');
        $f['waitingList']->update(['status' => WaitingListStatusEnum::Converted]);

        $this->expectException(\RuntimeException::class);
        app(WaitlistConversionService::class)->convertToBooking(
            $f['waitingList'], $f['destTrip'], [['category_id' => $f['destCat']->id, 'count' => 1]], $f['admin']->id
        );
    }

    public function test_cannot_convert_to_a_trip_in_a_different_tenant(): void
    {
        $f1 = $this->makeFixture('008a');
        $f2 = $this->makeFixture('008b');

        $this->expectException(\InvalidArgumentException::class);
        app(WaitlistConversionService::class)->convertToBooking(
            $f1['waitingList'], $f2['destTrip'], [['category_id' => $f2['destCat']->id, 'count' => 1]], $f1['admin']->id
        );
    }

    // ------------------------------------------------------------------
    // Audit trail
    // ------------------------------------------------------------------

    public function test_conversion_writes_both_activity_log_entries(): void
    {
        $f = $this->makeFixture('009');
        $this->actingAs($f['admin']);

        $booking = app(WaitlistConversionService::class)->convertToBooking(
            $f['waitingList'], $f['destTrip'], [['category_id' => $f['destCat']->id, 'count' => 2]], $f['admin']->id
        );

        $bookingLog = Activity::where('subject_type', \App\Models\Booking::class)
            ->where('subject_id', $booking->id)
            ->where('description', 'booking_created_from_waitlist')
            ->first();
        $this->assertNotNull($bookingLog);
        $this->assertSame($f['admin']->id, $bookingLog->causer_id);
        $this->assertSame($f['waitingList']->id, $bookingLog->properties['waiting_list_id']);
        $this->assertSame($f['destTrip']->id, $bookingLog->properties['destination_trip_instance_id']);
        $this->assertContains($f['sourceTrip']->id, $bookingLog->properties['source_trip_instance_ids']);

        $waitlistLog = Activity::where('subject_type', WaitingList::class)
            ->where('subject_id', $f['waitingList']->id)
            ->where('description', 'waitlist_converted')
            ->first();
        $this->assertNotNull($waitlistLog);
        $this->assertSame($booking->id, $waitlistLog->properties['booking_id']);
        $this->assertSame($f['destTrip']->id, $waitlistLog->properties['destination_trip_instance_id']);
    }

    public function test_activity_log_resource_labels_waiting_list_subjects_correctly(): void
    {
        $f = $this->makeFixture('010');
        $this->actingAs($f['admin']);
        app(WaitlistConversionService::class)->convertToBooking(
            $f['waitingList'], $f['destTrip'], [['category_id' => $f['destCat']->id, 'count' => 1]], $f['admin']->id
        );

        $waitlistLog = Activity::where('subject_type', WaitingList::class)->where('subject_id', $f['waitingList']->id)->firstOrFail();

        $page = new \App\Filament\Resources\ActivityLogResource\Pages\ListActivityLogs();
        $table = \App\Filament\Resources\ActivityLogResource::table(new \Filament\Tables\Table($page));
        $column = null;
        foreach ($table->getColumns() as $c) {
            if ($c->getName() === 'subject_type') {
                $column = $c;
            }
        }
        $this->assertNotNull($column);
        $label = $column->record($waitlistLog)->getState();
        $this->assertSame('طلب انتظار #' . $f['waitingList']->id, $label, 'WaitingList subjects must render a proper Arabic label, not the generic class-name fallback.');
    }

    // ------------------------------------------------------------------
    // Report 4: conversion attributes to the ORIGINAL template, not the destination
    // ------------------------------------------------------------------

    public function test_report_4_attributes_the_conversion_to_the_original_template_not_the_destination(): void
    {
        $f = $this->makeFixture('011');
        $this->actingAs($f['admin']);
        Filament::setTenant($f['tenant'], true);

        app(WaitlistConversionService::class)->convertToBooking(
            $f['waitingList'], $f['destTrip'], [['category_id' => $f['destCat']->id, 'count' => 2]], $f['admin']->id
        );

        $component = Livewire::test(WaitlistCancellationHealthReport::class);
        $records = $component->instance()->getTableRecords();

        $sourceRecord = $records->firstWhere('id', $f['sourceTemplate']->id);
        $sourceRecord->load('tripInstances.waitingLists');
        $sourceWaitlist = $sourceRecord->tripInstances->flatMap->waitingLists;
        $this->assertCount(1, $sourceWaitlist, 'The converted entry must still count against the ORIGINAL template it was waitlisted for.');
        $this->assertSame(WaitingListStatusEnum::Converted, $sourceWaitlist->first()->status);

        $destRecord = $records->firstWhere('id', $f['destTemplate']->id);
        $destRecord->load('tripInstances.waitingLists');
        $destWaitlist = $destRecord->tripInstances->flatMap->waitingLists;
        $this->assertCount(0, $destWaitlist, 'The destination template must NOT gain a waitlist entry it was never actually waitlisted for -- no double counting.');
    }

    // ------------------------------------------------------------------
    // UI: destination-trip picker is tenant-scoped from the start
    // ------------------------------------------------------------------

    public function test_conversion_form_destination_picker_only_shows_the_same_tenants_trips(): void
    {
        $fA = $this->makeFixture('012a');
        $fB = $this->makeFixture('012b');

        $form = \Filament\Forms\Form::make(new CreateWaitingList())->schema(WaitlistConversionForm::schema());
        $field = null;
        foreach ($form->getComponents() as $c) {
            if (method_exists($c, 'getName') && $c->getName() === 'destination_trip_instance_id') {
                $field = $c;
            }
        }
        $this->assertNotNull($field);

        // The Select's options() closure resolves $record via the form's own bound model --
        // fill it in directly the same way Filament's own record-scoped evaluate() does.
        $form->model($fA['waitingList']);
        $options = $field->getOptions();

        $this->assertArrayHasKey($fA['destTrip']->id, $options);
        $this->assertArrayNotHasKey($fB['destTrip']->id, $options, "Tenant A's conversion picker must NOT show Tenant B's trip.");
    }

    // ------------------------------------------------------------------
    // WaitingListStatusEnum mismatch fix
    // ------------------------------------------------------------------

    public function test_waiting_list_resource_status_select_uses_the_real_enum_value(): void
    {
        $form = WaitingListResource::form(Form::make(new CreateWaitingList()));
        $field = null;
        foreach ($form->getComponents() as $c) {
            if (method_exists($c, 'getName') && $c->getName() === 'status') {
                $field = $c;
            }
        }
        $this->assertNotNull($field);
        $options = $field->getOptions();

        $this->assertArrayHasKey(WaitingListStatusEnum::Converted->value, $options, "The status Select must use the real enum value 'converted'.");
        $this->assertArrayNotHasKey('converted_to_booking', $options, "The stale 'converted_to_booking' value must be gone -- it doesn't match any real enum case.");
    }

    // ------------------------------------------------------------------
    // WaitingListsRelationManager ambiguous-column crash (MySQL-only, invisible to this
    // SQLite-run test suite) -- caught during this ticket's own live verification, not related
    // to the conversion feature itself, but blocked verifying it on that surface until fixed.
    // ------------------------------------------------------------------

    public function test_relation_manager_default_sort_qualifies_the_ambiguous_created_at_column(): void
    {
        // SQLite tolerates an unqualified ORDER BY created_at across this pivot join (silently
        // picks one side), which is exactly why this bug was invisible to every test in this
        // suite despite crashing outright on MySQL (ambiguous column). Asserting the actual
        // compiled SQL -- not just "did it throw" -- is the only way this test can catch a
        // regression back to the unqualified column regardless of which DB driver runs it.
        $f = $this->makeFixture('014');
        \DB::enableQueryLog();

        Livewire::test(\App\Filament\Resources\TripInstanceResource\RelationManagers\WaitingListsRelationManager::class, [
            'ownerRecord' => $f['sourceTrip'],
            'pageClass' => \App\Filament\Resources\TripInstanceResource\Pages\EditTripInstance::class,
        ])->instance()->getTableRecords();

        $orderByQueries = collect(\DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'order by'));
        \DB::disableQueryLog();

        $this->assertTrue($orderByQueries->isNotEmpty(), 'Expected at least one ORDER BY query while rendering the relation manager table.');
        $this->assertTrue(
            $orderByQueries->every(fn ($q) => str_contains($q['query'], '"waiting_lists"."created_at"') || str_contains($q['query'], '`waiting_lists`.`created_at`')),
            'defaultSort must reference the qualified waiting_lists.created_at column -- an unqualified created_at is ambiguous once this relationship joins the pivot table.'
        );
    }

    public function test_a_genuinely_converted_waiting_list_row_renders_correctly_in_the_table(): void
    {
        $f = $this->makeFixture('013');
        $f['waitingList']->update(['status' => WaitingListStatusEnum::Converted]);

        $table = WaitingListResource::table(new \Filament\Tables\Table(new \App\Filament\Resources\WaitingListResource\Pages\ListWaitingLists()));
        $column = null;
        foreach ($table->getColumns() as $c) {
            if ($c->getName() === 'status') {
                $column = $c;
            }
        }
        $this->assertNotNull($column);
        $column->record($f['waitingList']->fresh());
        $label = $column->formatState($column->getState());
        $this->assertSame('تحول لحجز', $label, 'A real Converted row must render the Arabic label, not the raw enum value.');
    }
}
