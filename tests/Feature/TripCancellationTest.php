<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Enums\TripStatusEnum;
use App\Models\Customer;
use App\Models\InventoryLedger;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\TripInstance;
use App\Models\TripPassengerCategory;
use App\Models\TripTemplate;
use App\Models\User;
use App\Services\BookingService;
use App\Services\CreateBookingService;
use App\Services\TripService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Regression coverage for:
 *  - PaymentStatus::RefundPending/Refunded (new cases, must not break getLabel()/getColor()).
 *  - BookingService::cancelBooking() tracking refund liability via payment_status without
 *    ever touching grand_total/total_paid/balance_due.
 *  - TripService::cancelTrip() blocking Completed/InProgress trips (no exceptions), while
 *    Draft/Active/Closed remain cancellable.
 *  - cancel_trip action visibility respecting the same block list (and the fixed
 *    enum-vs-string comparison bug it used to have).
 *  - The three "set trip status directly" bypass paths no longer offering 'cancelled'.
 */
class TripCancellationTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_status_enum_is_exhaustive_for_label_and_color(): void
    {
        foreach (PaymentStatus::cases() as $case) {
            $this->assertIsString($case->getLabel(), "PaymentStatus::{$case->name} must have a label.");
            $this->assertNotNull($case->getColor(), "PaymentStatus::{$case->name} must have a color.");
        }

        $this->assertContains(PaymentStatus::RefundPending, PaymentStatus::cases());
        $this->assertContains(PaymentStatus::Refunded, PaymentStatus::cases());
    }

    /**
     * @return array{tenant: Tenant, customer: Customer, instance: TripInstance, cat: TripPassengerCategory, booking: \App\Models\Booking}
     */
    private function makePaidBooking(string $suffix, float $price = 100.00): array
    {
        $tenant = Tenant::create(['name' => "Agency {$suffix}", 'slug' => "agency-tc-{$suffix}", 'domain' => "{$suffix}.zatara.com"]);
        $customer = Customer::create(['name' => 'Jane', 'phone' => "0591{$suffix}", 'tenant_id' => $tenant->id]);
        $template = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'Tour', 'base_price' => $price]);
        $instance = TripInstance::create([
            'tenant_id' => $tenant->id,
            'trip_template_id' => $template->id,
            'start_date' => now()->addDays(5),
            'end_date' => now()->addDays(10),
            'available_seats' => 10,
            'status' => TripStatusEnum::Active->value,
        ]);
        $cat = TripPassengerCategory::create([
            'tenant_id' => $tenant->id, 'trip_instance_id' => $instance->id,
            'name' => 'Adult', 'price' => $price, 'requires_seat' => true,
        ]);

        $booking = (new CreateBookingService())->execute([
            'tenant_id' => $tenant->id,
            'trip_instance_id' => $instance->id,
            'customer_id' => $customer->id,
            'passengersData' => [
                ['trip_passenger_category_id' => $cat->id, 'first_name' => 'P1', 'last_name' => 'Test'],
            ],
        ]);

        return compact('tenant', 'customer', 'instance', 'cat', 'booking');
    }

    public function test_cancel_booking_sets_refund_pending_for_previously_paid_booking_without_touching_amounts(): void
    {
        $f = $this->makePaidBooking('a');
        $booking = $f['booking'];

        app(BookingService::class)->recordPayment(
            booking: $booking,
            amount: 100.00,
            method: 'cash',
            receivedBy: null,
            type: PaymentType::FULL
        );

        $beforeGrandTotal = DB::table('bookings')->where('id', $booking->id)->value('grand_total');
        $beforeTotalPaid = DB::table('bookings')->where('id', $booking->id)->value('total_paid');
        $beforeBalanceDue = DB::table('bookings')->where('id', $booking->id)->value('balance_due');
        $this->assertEquals(PaymentStatus::Paid->value, $booking->fresh()->payment_status->value);

        app(BookingService::class)->cancelBooking($booking, 'customer_request');

        $fresh = $booking->fresh();
        $this->assertEquals(BookingStatus::Cancelled, $fresh->booking_status);
        $this->assertEquals(PaymentStatus::RefundPending, $fresh->payment_status);

        // Financial amount fields are byte-for-byte unchanged from before cancellation.
        $this->assertEquals($beforeGrandTotal, DB::table('bookings')->where('id', $booking->id)->value('grand_total'));
        $this->assertEquals($beforeTotalPaid, DB::table('bookings')->where('id', $booking->id)->value('total_paid'));
        $this->assertEquals($beforeBalanceDue, DB::table('bookings')->where('id', $booking->id)->value('balance_due'));
    }

    public function test_cancel_booking_leaves_unpaid_payment_status_unchanged(): void
    {
        $f = $this->makePaidBooking('b');
        $booking = $f['booking'];

        $this->assertEquals(PaymentStatus::Unpaid, $booking->fresh()->payment_status);

        app(BookingService::class)->cancelBooking($booking, 'customer_request');

        $fresh = $booking->fresh();
        $this->assertEquals(BookingStatus::Cancelled, $fresh->booking_status);
        $this->assertEquals(PaymentStatus::Unpaid, $fresh->payment_status, 'An Unpaid booking must stay Unpaid on cancellation, not become RefundPending.');
    }

    public function test_cancel_booking_refund_pending_survives_subsequent_payment_create(): void
    {
        $f = $this->makePaidBooking('c');
        $booking = $f['booking'];

        app(BookingService::class)->recordPayment(
            booking: $booking,
            amount: 50.00,
            method: 'cash',
            receivedBy: null,
            type: PaymentType::DEPOSIT
        );

        app(BookingService::class)->cancelBooking($booking, 'customer_request');
        $this->assertEquals(PaymentStatus::RefundPending, $booking->fresh()->payment_status);

        // Simulate a future refund-execution record (any Payment::create() fires
        // PaymentObserver::created() -> recalculateTotals()). recalculateTotals() has an
        // existing P0-6 guard (BookingService.php ~line 480) that no-ops entirely once
        // booking_status is Cancelled - proving that guard protects the new RefundPending
        // value from being silently recomputed back to Paid/PartiallyPaid/Unpaid.
        Payment::create([
            'tenant_id' => $f['tenant']->id,
            'booking_id' => $booking->id,
            'amount' => -50.00,
            'payment_method' => 'cash',
            'type' => PaymentType::REVERSAL,
        ]);

        $this->assertEquals(
            PaymentStatus::RefundPending,
            $booking->fresh()->payment_status,
            'payment_status must not be clobbered back by recalculateTotals() after a subsequent Payment::create().'
        );
    }

    private function makeTripFixture(string $suffix, TripStatusEnum $status): array
    {
        $tenant = Tenant::create(['name' => "Agency {$suffix}", 'slug' => "agency-tcs-{$suffix}", 'domain' => "{$suffix}.zatara.com"]);
        $customer = Customer::create(['name' => 'Jane', 'phone' => "0592{$suffix}", 'tenant_id' => $tenant->id]);
        $template = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'Tour', 'base_price' => 100]);
        $instance = TripInstance::create([
            'tenant_id' => $tenant->id,
            'trip_template_id' => $template->id,
            'start_date' => now()->addDays(5),
            'end_date' => now()->addDays(10),
            'available_seats' => 10,
            'status' => TripStatusEnum::Active->value,
        ]);
        $cat = TripPassengerCategory::create([
            'tenant_id' => $tenant->id, 'trip_instance_id' => $instance->id,
            'name' => 'Adult', 'price' => 100, 'requires_seat' => true,
        ]);

        $booking = (new CreateBookingService())->execute([
            'tenant_id' => $tenant->id,
            'trip_instance_id' => $instance->id,
            'customer_id' => $customer->id,
            'passengersData' => [
                ['trip_passenger_category_id' => $cat->id, 'first_name' => 'P1', 'last_name' => 'Test'],
            ],
        ]);

        // Set the trip to the status under test AFTER booking creation, so the booking
        // creation path itself (which requires an active-enough trip) isn't affected.
        DB::table('trip_instances')->where('id', $instance->id)->update(['status' => $status->value]);

        return compact('tenant', 'customer', 'instance', 'cat', 'booking');
    }

    public function test_cancel_trip_blocked_on_completed_trip_with_zero_mutation(): void
    {
        $f = $this->makeTripFixture('d', TripStatusEnum::Completed);
        $ledgerCountBefore = InventoryLedger::count();

        $this->expectException(\RuntimeException::class);
        try {
            app(TripService::class)->cancelTrip($f['instance'], 'test');
        } finally {
            $this->assertEquals('completed', DB::table('trip_instances')->where('id', $f['instance']->id)->value('status'));
            $this->assertEquals(BookingStatus::Pending->value, DB::table('bookings')->where('id', $f['booking']->id)->value('booking_status'));
            $this->assertSame($ledgerCountBefore, InventoryLedger::count());
        }
    }

    public function test_cancel_trip_blocked_on_inprogress_trip_with_zero_mutation(): void
    {
        $f = $this->makeTripFixture('e', TripStatusEnum::InProgress);
        $ledgerCountBefore = InventoryLedger::count();

        $this->expectException(\RuntimeException::class);
        try {
            app(TripService::class)->cancelTrip($f['instance'], 'test');
        } finally {
            $this->assertEquals('in_progress', DB::table('trip_instances')->where('id', $f['instance']->id)->value('status'));
            $this->assertEquals(BookingStatus::Pending->value, DB::table('bookings')->where('id', $f['booking']->id)->value('booking_status'));
            $this->assertSame($ledgerCountBefore, InventoryLedger::count());
        }
    }

    public function test_cancel_trip_succeeds_on_draft_active_and_closed(): void
    {
        foreach ([TripStatusEnum::Draft, TripStatusEnum::Active, TripStatusEnum::Closed] as $status) {
            $f = $this->makeTripFixture('f' . $status->value, $status);

            $count = app(TripService::class)->cancelTrip($f['instance'], 'test');

            $this->assertSame(1, $count, "cancelTrip() should cascade-cancel exactly 1 booking for a {$status->value} trip.");
            $this->assertEquals('cancelled', DB::table('trip_instances')->where('id', $f['instance']->id)->value('status'));
            $this->assertEquals(BookingStatus::Cancelled, $f['booking']->fresh()->booking_status);
            $this->assertEquals(10, $f['instance']->fresh()->getRemainingSeatsAttribute(), 'Inventory must be released for a status this ticket allows cancelling.');
        }
    }

    public function test_cancel_trip_is_idempotent_when_already_cancelled(): void
    {
        $f = $this->makeTripFixture('g', TripStatusEnum::Active);

        $first = app(TripService::class)->cancelTrip($f['instance'], 'test');
        $this->assertSame(1, $first);

        $second = app(TripService::class)->cancelTrip($f['instance']->fresh(), 'test');
        $this->assertSame(0, $second, 'A repeated cancelTrip() call on an already-cancelled trip must be a no-op.');
    }

    private function makeAgencyAdmin(Tenant $tenant): User
    {
        Role::firstOrCreate(['name' => 'agency_admin']);
        Permission::firstOrCreate(['name' => 'panel_access_placeholder']); // ensure permission tables exist
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::create(['name' => 'Admin', 'phone' => '0599999999']);
        $user->tenants()->attach($tenant);
        setPermissionsTeamId($tenant->id);
        $user->assignRole('agency_admin');

        return $user;
    }

    public function test_cancel_trip_action_visibility_blocks_completed_inprogress_cancelled_and_allows_others(): void
    {
        $tenant = Tenant::create(['name' => 'Agency Vis', 'slug' => 'agency-tc-vis', 'domain' => 'vis.zatara.com']);
        $template = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'Tour', 'base_price' => 100]);
        $admin = $this->makeAgencyAdmin($tenant);
        $this->actingAs($admin);

        $page = new \App\Filament\Resources\TripInstanceResource\Pages\ListTripInstances();
        $table = \App\Filament\Resources\TripInstanceResource::table(new \Filament\Tables\Table($page));
        $action = $table->getAction('cancel_trip');
        $this->assertNotNull($action, "The 'cancel_trip' action must be registered on TripInstanceResource's table.");

        $expectations = [
            TripStatusEnum::Draft->value => true,
            TripStatusEnum::Active->value => true,
            TripStatusEnum::Closed->value => true,
            TripStatusEnum::Completed->value => false,
            TripStatusEnum::InProgress->value => false,
            TripStatusEnum::Cancelled->value => false,
        ];

        foreach ($expectations as $statusValue => $shouldBeVisible) {
            $instance = TripInstance::create([
                'tenant_id' => $tenant->id,
                'trip_template_id' => $template->id,
                'start_date' => now()->addDays(5),
                'end_date' => now()->addDays(10),
                'available_seats' => 10,
                'status' => $statusValue,
            ]);

            $action->record($instance);
            $this->assertSame(
                $shouldBeVisible,
                $action->isVisible(),
                "cancel_trip visibility for status '{$statusValue}' should be " . ($shouldBeVisible ? 'true' : 'false') . '.'
            );
        }
    }

    public function test_trip_instance_resource_edit_form_status_select_excludes_cancelled(): void
    {
        $source = file_get_contents(app_path('Filament/Resources/TripInstanceResource.php'));
        $formSectionStart = strpos($source, "Select::make('status')");
        $this->assertNotFalse($formSectionStart);
        $formSectionSlice = substr($source, $formSectionStart, 300);

        $this->assertStringContainsString("'active' => 'نشط'", $formSectionSlice);
        $this->assertStringNotContainsString("'cancelled' =>", $formSectionSlice);
    }

    public function test_trip_instance_resource_bulk_status_change_excludes_cancelled(): void
    {
        $source = file_get_contents(app_path('Filament/Resources/TripInstanceResource.php'));
        $bulkActionStart = strpos($source, "BulkAction::make('bulk_status_change')");
        $this->assertNotFalse($bulkActionStart);
        $bulkActionSlice = substr($source, $bulkActionStart, 1500);

        $this->assertStringNotContainsString('->options(\App\Enums\TripStatusEnum::class)', $bulkActionSlice, 'bulk_status_change must no longer pass the raw enum class (which would include Cancelled).');
        $this->assertStringContainsString('TripStatusEnum::Cancelled', $bulkActionSlice, 'bulk_status_change must explicitly reject the Cancelled case.');

        // Behavioral confirmation: build the actual bulk action and inspect its rendered
        // options rather than trusting the source text alone.
        $page = new \App\Filament\Resources\TripInstanceResource\Pages\ListTripInstances();
        $table = \App\Filament\Resources\TripInstanceResource::table(new \Filament\Tables\Table($page));
        $bulkAction = null;
        foreach ($table->getBulkActions() as $group) {
            if (method_exists($group, 'getActions')) {
                foreach ($group->getActions() as $a) {
                    if ($a->getName() === 'bulk_status_change') {
                        $bulkAction = $a;
                    }
                }
            }
        }
        $this->assertNotNull($bulkAction, "Could not locate the 'bulk_status_change' bulk action to inspect its options.");

        $form = $bulkAction->getForm(\Filament\Forms\Form::make($page));
        $field = null;
        foreach ($form->getComponents() as $component) {
            if (method_exists($component, 'getName') && $component->getName() === 'new_status') {
                $field = $component;
            }
        }

        if ($field !== null) {
            $options = $field->getOptions();
            $this->assertArrayNotHasKey(TripStatusEnum::Cancelled->value, $options, 'bulk_status_change options must not include Cancelled.');
            $this->assertArrayHasKey(TripStatusEnum::Active->value, $options, 'bulk_status_change options must still include Active.');
        }
    }

    public function test_trip_instances_relation_manager_status_select_excludes_cancelled(): void
    {
        $source = file_get_contents(app_path('Filament/Resources/TripTemplateResource/RelationManagers/TripInstancesRelationManager.php'));
        $formSectionStart = strpos($source, "Select::make('status')");
        $this->assertNotFalse($formSectionStart);
        $formSectionSlice = substr($source, $formSectionStart, 300);

        $this->assertStringContainsString("'active' => 'فعال'", $formSectionSlice);
        $this->assertStringNotContainsString("'cancelled' =>", $formSectionSlice);
    }
}
