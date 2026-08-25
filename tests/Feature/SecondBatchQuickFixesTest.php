<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\PaymentType;
use App\Enums\WaitingListStatusEnum;
use App\Filament\Pages\QuickBookingPage;
use App\Filament\Resources\TripInstanceResource;
use App\Filament\Resources\TripInstanceResource\RelationManagers\PackageOptionsRelationManager;
use App\Filament\Resources\TripTemplateResource\Pages\EditTripTemplate;
use App\Filament\Resources\TripTemplateResource\RelationManagers\TripInstancesRelationManager;
use App\Livewire\CheckoutWizard;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\GuestSession;
use App\Models\InventoryLedger;
use App\Models\Tenant;
use App\Models\TripInstance;
use App\Models\TripPassengerCategory;
use App\Models\TripTemplate;
use App\Models\User;
use App\Models\WaitingList;
use App\Services\BookingService;
use App\Services\CreateBookingService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Regression coverage for the second batch of audit safe-fixes:
 *  1. TripInstancesRelationManager inheriting the parent template's currency (manual create +
 *     bulk_schedule) instead of relying on the DB column default.
 *  2. Admin "Create Booking" initial payment using the trip's real currency and routing through
 *     BookingService::recordPayment() (same fix applied to CreateBookingService's initial-deposit
 *     block, since both are the same code path).
 *  3. booking_status no longer overridable from the admin Create Booking form.
 *  4. ProcessWaitingListJob / waitinglist:sweep deleted.
 *  5. BookingObserver's dead cancellation branch removed.
 *  6. Duplicate embedded PackageOption repeater removed from TripInstancesRelationManager.
 *  7. QuickBookingPage's payment step actually recording a payment.
 *  8. Waitlist-to-booking conversion reusing the waitlist's hold, and ReleaseWaitlistHold no
 *     longer overwriting an already-Converted waiting list entry.
 */
class SecondBatchQuickFixesTest extends TestCase
{
    use RefreshDatabase;

    private CreateBookingService $createBookingService;
    private BookingService $bookingService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createBookingService = new CreateBookingService();
        $this->bookingService = new BookingService();
    }

    /**
     * @return array{tenant: Tenant, customer: Customer, template: TripTemplate, instance: TripInstance, cat: TripPassengerCategory}
     */
    private function makeFixture(string $suffix, string $currency = 'USD', int $availableSeats = 10, float $price = 100.00): array
    {
        $tenant = Tenant::create(['name' => "Agency {$suffix}", 'slug' => "agency-b2-{$suffix}", 'domain' => "{$suffix}.zatara.com"]);
        $customer = Customer::create(['name' => 'Jane', 'phone' => "0594{$suffix}", 'tenant_id' => $tenant->id]);
        $template = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'Tour', 'base_price' => $price, 'currency' => $currency]);
        $instance = TripInstance::create([
            'tenant_id' => $tenant->id,
            'trip_template_id' => $template->id,
            'start_date' => now()->addDays(5),
            'end_date' => now()->addDays(10),
            'available_seats' => $availableSeats,
            'status' => 'active',
            'currency' => $currency,
        ]);
        $cat = TripPassengerCategory::create([
            'tenant_id' => $tenant->id, 'trip_instance_id' => $instance->id,
            'name' => 'Adult', 'price' => $price, 'requires_seat' => true,
        ]);

        return compact('tenant', 'customer', 'template', 'instance', 'cat');
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

    // ------------------------------------------------------------------
    // 1. TripInstancesRelationManager currency inheritance
    // ------------------------------------------------------------------

    public function test_manual_create_action_inherits_template_currency(): void
    {
        $f = $this->makeFixture('001', currency: 'ILS');
        $admin = $this->makeAgencyAdmin($f['tenant'], '0791100001');
        $this->actingAs($admin);
        Filament::setTenant($f['tenant'], true);

        Livewire::test(TripInstancesRelationManager::class, [
            'ownerRecord' => $f['template'],
            'pageClass' => EditTripTemplate::class,
        ])->callTableAction('create', data: [
            'start_date' => now()->addDays(20)->toDateString(),
            'end_date' => now()->addDays(21)->toDateString(),
            'available_seats' => 15,
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('trip_instances', [
            'trip_template_id' => $f['template']->id,
            'currency' => 'ILS',
            'available_seats' => 15,
        ]);
    }

    public function test_bulk_schedule_action_inherits_template_currency(): void
    {
        $f = $this->makeFixture('002', currency: 'ILS');
        $admin = $this->makeAgencyAdmin($f['tenant'], '0791100002');
        $this->actingAs($admin);
        Filament::setTenant($f['tenant'], true);

        $targetDate = now()->addDays(30);

        Livewire::test(TripInstancesRelationManager::class, [
            'ownerRecord' => $f['template'],
            'pageClass' => EditTripTemplate::class,
        ])->callTableAction('bulk_schedule', data: [
            'start_date_range' => $targetDate->toDateString(),
            'end_date_range' => $targetDate->toDateString(),
            'days_of_week' => [$targetDate->dayOfWeek],
            'duration_days' => 1,
            'available_seats' => 12,
        ]);

        $this->assertDatabaseHas('trip_instances', [
            'trip_template_id' => $f['template']->id,
            'currency' => 'ILS',
            'available_seats' => 12,
        ]);
    }

    // ------------------------------------------------------------------
    // 2. Admin initial-payment currency + recordPayment() routing
    //    (same CreateBookingService code path — covers the admin form too)
    // ------------------------------------------------------------------

    public function test_initial_payment_uses_trip_currency_not_hardcoded_usd(): void
    {
        $f = $this->makeFixture('003', currency: 'ILS');
        $admin = $this->makeAgencyAdmin($f['tenant'], '0791100003');

        $booking = $this->createBookingService->execute([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'customer_id' => $f['customer']->id,
            'user_id' => $admin->id,
            'passengersData' => [
                ['trip_passenger_category_id' => $f['cat']->id, 'first_name' => 'P', 'last_name' => '1'],
            ],
            'initial_payment_amount' => 40.00,
            'initial_payment_method' => 'cash',
        ]);

        $payment = $booking->payments()->first();
        $this->assertNotNull($payment);
        $this->assertEquals('ILS', $payment->currency, 'Initial payment must be stamped with the trip\'s real currency (Tenant has no currency column at all — the old code always fell back to a hardcoded USD).');
        $this->assertEquals(40.00, $payment->amount);
        $this->assertEquals(PaymentType::FULL, $payment->type);

        // Went through recordPayment() -> recalculateTotals(), not a bare Payment::create().
        $fresh = $booking->fresh();
        $this->assertEquals(40.00, $fresh->total_paid);
        $this->assertDatabaseHas('activity_log', ['description' => 'payment_recorded', 'subject_id' => $booking->id]);
    }

    public function test_initial_payment_deposit_type_maps_to_deposit_payment_type(): void
    {
        $f = $this->makeFixture('004');
        $admin = $this->makeAgencyAdmin($f['tenant'], '0791100004');

        $booking = $this->createBookingService->execute([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'customer_id' => $f['customer']->id,
            'user_id' => $admin->id,
            'passengersData' => [
                ['trip_passenger_category_id' => $f['cat']->id, 'first_name' => 'P', 'last_name' => '1'],
            ],
            'payment_type' => 'deposit',
            'initial_payment_amount' => 30.00,
            'initial_payment_method' => 'cash',
        ]);

        $payment = $booking->payments()->first();
        $this->assertEquals(PaymentType::DEPOSIT, $payment->type);
    }

    public function test_initial_payment_exceeding_grand_total_is_rejected_and_rolls_back_whole_booking(): void
    {
        $f = $this->makeFixture('005', price: 100.00);

        $this->expectException(\InvalidArgumentException::class);

        try {
            $this->createBookingService->execute([
                'tenant_id' => $f['tenant']->id,
                'trip_instance_id' => $f['instance']->id,
                'customer_id' => $f['customer']->id,
                'passengersData' => [
                    ['trip_passenger_category_id' => $f['cat']->id, 'first_name' => 'P', 'last_name' => '1'],
                ],
                'initial_payment_amount' => 500.00,
                'initial_payment_method' => 'cash',
            ]);
        } finally {
            $this->assertSame(0, Booking::where('tenant_id', $f['tenant']->id)->count(), 'The whole booking creation must roll back, not just the payment.');
        }
    }

    // ------------------------------------------------------------------
    // 3. booking_status no longer overridable on create
    // ------------------------------------------------------------------

    public function test_create_booking_page_no_longer_overrides_booking_status_from_form_data(): void
    {
        $source = file_get_contents(app_path('Filament/Resources/BookingResource/Pages/CreateBooking.php'));
        $this->assertStringNotContainsString("isset(\$data['booking_status'])", $source);
    }

    public function test_booking_status_field_hidden_on_create_form(): void
    {
        $source = file_get_contents(app_path('Filament/Resources/BookingResource.php'));
        $selectStart = strpos($source, "Select::make('booking_status')");
        $this->assertNotFalse($selectStart);
        $slice = substr($source, $selectStart, 3500);
        $this->assertStringContainsString('->hidden(', $slice, 'booking_status select must be hidden in some context (create).');
    }

    public function test_a_stray_booking_status_key_in_the_payload_has_no_effect_on_the_created_booking(): void
    {
        $f = $this->makeFixture('006');

        // CreateBookingService::execute() never reads data['booking_status'] at all — simulates
        // what used to happen when CreateBooking::handleRecordCreation() applied it afterward.
        $booking = $this->createBookingService->execute([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'customer_id' => $f['customer']->id,
            'passengersData' => [
                ['trip_passenger_category_id' => $f['cat']->id, 'first_name' => 'P', 'last_name' => '1'],
            ],
            'booking_status' => 'confirmed',
        ]);

        $this->assertEquals(BookingStatus::Pending, $booking->fresh()->booking_status, 'A stray booking_status in the payload must not confirm a $0-paid booking.');
    }

    // ------------------------------------------------------------------
    // 4. Dead waitlist job/command deleted
    // ------------------------------------------------------------------

    public function test_process_waiting_list_job_and_sweep_command_are_deleted(): void
    {
        $this->assertFalse(class_exists(\App\Jobs\ProcessWaitingListJob::class));
        $this->assertFalse(class_exists(\App\Console\Commands\WaitingListSweep::class));
    }

    // ------------------------------------------------------------------
    // 5. BookingObserver dead cancellation branch removed
    // ------------------------------------------------------------------

    public function test_plain_eloquent_status_flip_to_cancelled_no_longer_releases_inventory(): void
    {
        $f = $this->makeFixture('007', availableSeats: 5);

        $booking = $this->createBookingService->execute([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'customer_id' => $f['customer']->id,
            'passengersData' => [
                ['trip_passenger_category_id' => $f['cat']->id, 'first_name' => 'P', 'last_name' => '1'],
            ],
        ]);

        $before = $f['instance']->fresh()->getRemainingSeatsAttribute();
        $this->assertEquals(4, $before);

        // A bare Eloquent save — NOT BookingService::cancelBooking() — is exactly what the
        // removed observer branch used to react to.
        $booking->booking_status = BookingStatus::Cancelled;
        $booking->save();

        $this->assertEquals(
            $before,
            $f['instance']->fresh()->getRemainingSeatsAttribute(),
            'No inventory should be released by a bare Eloquent status flip now that the dead observer branch is removed.'
        );
    }

    // ------------------------------------------------------------------
    // 6. Duplicate embedded PackageOption repeater removed
    // ------------------------------------------------------------------

    public function test_trip_instances_relation_manager_no_longer_embeds_package_options_repeater(): void
    {
        $source = file_get_contents(app_path('Filament/Resources/TripTemplateResource/RelationManagers/TripInstancesRelationManager.php'));
        $this->assertStringNotContainsString("Repeater::make('packageOptions')", $source);
    }

    public function test_package_options_relation_manager_still_registered_as_single_source_of_truth(): void
    {
        $relations = TripInstanceResource::getRelations();
        $this->assertContains(PackageOptionsRelationManager::class, $relations);
    }

    // ------------------------------------------------------------------
    // 7. QuickBookingPage payment wiring
    // ------------------------------------------------------------------

    public function test_quick_booking_page_records_payment_for_selected_method(): void
    {
        $f = $this->makeFixture('008', price: 75.00);
        $admin = $this->makeAgencyAdmin($f['tenant'], '0791100008');
        $this->actingAs($admin);
        Filament::setTenant($f['tenant'], true);

        $component = Livewire::test(QuickBookingPage::class)
            ->set('customer_id', $f['customer']->id)
            ->set('trip_instance_id', $f['instance']->id)
            ->set('passengers', [[
                'first_name' => 'Sam',
                'last_name' => 'Agent',
                'document_type' => 'national_id',
                'document_number' => '999',
                'trip_passenger_category_id' => $f['cat']->id,
            ]])
            ->set('payment_method', 'transfer')
            ->call('submitBooking');

        $bookingId = $component->get('booking_id');
        $this->assertNotNull($bookingId, 'Booking must have been created.');

        $booking = Booking::find($bookingId);
        $payment = $booking->payments()->first();

        $this->assertNotNull($payment, 'Selecting a payment method must actually create a Payment row.');
        $this->assertEquals(75.00, $payment->amount);
        $this->assertEquals('transfer', $payment->payment_method);
        $this->assertEquals(PaymentType::FULL, $payment->type);
        $this->assertEquals(75.00, $booking->fresh()->total_paid);
        $this->assertEquals(BookingStatus::Confirmed, $booking->fresh()->booking_status);
    }

    // ------------------------------------------------------------------
    // 8. Waitlist hold reuse + ReleaseWaitlistHold Converted-skip
    // ------------------------------------------------------------------

    public function test_checkout_wizard_lead_capture_reuses_waitlist_hold_instead_of_opening_second_one(): void
    {
        $f = $this->makeFixture('009', availableSeats: 2);

        // Simulate a promoted waitlist entry with its hold already created and persisted
        // (WaitlistAutoPromotion / send_link_now now both persist hold_id).
        $waitingList = WaitingList::create([
            'tenant_id' => $f['tenant']->id,
            'customer_name' => 'Waitlisted Customer',
            'phone_number' => '0599999009',
            'status' => WaitingListStatusEnum::Notified,
            'notified_at' => now(),
        ]);
        $hold = InventoryLedger::create([
            'trip_instance_id' => $f['instance']->id,
            'quantity' => -1,
            'type' => 'hold',
            'expires_at' => now()->addHours(2),
        ]);
        $waitingList->update(['hold_id' => $hold->id]);

        $this->assertEquals(1, $f['instance']->fresh()->getRemainingSeatsAttribute());

        Livewire::test(CheckoutWizard::class, ['tenant' => $f['tenant'], 'tripInstance' => $f['instance']])
            ->set('wl_id', $waitingList->id)
            ->set('form.passengers.0.first_name', 'Redeemed')
            ->set('form.passengers.0.last_name', 'Customer')
            ->set('form.email', 'redeemed@example.com')
            ->set('form.phone', '+966500000123')
            ->call('submitLeadCapture');

        $this->assertEquals(
            1,
            InventoryLedger::where('trip_instance_id', $f['instance']->id)->where('type', 'hold')->count(),
            'Only the reused waitlist hold should exist — no second, independent hold.'
        );

        $guestSession = GuestSession::where('trip_instance_id', $f['instance']->id)->first();
        $this->assertNotNull($guestSession);
        $this->assertEquals($hold->id, $guestSession->hold_id, 'The guest session must point at the reused waitlist hold, not a freshly created one.');
    }

    public function test_checkout_wizard_lead_capture_creates_own_hold_when_no_waitlist_context(): void
    {
        $f = $this->makeFixture('010', availableSeats: 5);

        Livewire::test(CheckoutWizard::class, ['tenant' => $f['tenant'], 'tripInstance' => $f['instance']])
            ->set('form.passengers.0.first_name', 'Direct')
            ->set('form.passengers.0.last_name', 'Customer')
            ->set('form.email', 'direct@example.com')
            ->set('form.phone', '+966500000124')
            ->call('submitLeadCapture');

        $this->assertEquals(1, InventoryLedger::where('trip_instance_id', $f['instance']->id)->where('type', 'hold')->count());
    }

    public function test_release_waitlist_hold_skips_status_overwrite_when_already_converted(): void
    {
        $f = $this->makeFixture('011');

        $waitingList = WaitingList::create([
            'tenant_id' => $f['tenant']->id,
            'customer_name' => 'Converted Customer',
            'phone_number' => '0599999011',
            'status' => WaitingListStatusEnum::Converted,
            'notified_at' => now(),
        ]);
        $hold = InventoryLedger::create([
            'trip_instance_id' => $f['instance']->id,
            'quantity' => -1,
            'type' => 'hold',
            'expires_at' => now()->subMinute(),
        ]);
        $waitingList->update(['hold_id' => $hold->id]);

        app(\App\Jobs\ReleaseWaitlistHold::class, ['holdId' => $hold->id, 'waitlistId' => $waitingList->id])->handle();

        $this->assertEquals(
            WaitingListStatusEnum::Converted,
            $waitingList->fresh()->status,
            'A successfully converted waiting list entry must not be silently reverted to Expired.'
        );
    }

    public function test_release_waitlist_hold_still_marks_expired_when_never_converted(): void
    {
        $f = $this->makeFixture('012');

        $waitingList = WaitingList::create([
            'tenant_id' => $f['tenant']->id,
            'customer_name' => 'No-show Customer',
            'phone_number' => '0599999012',
            'status' => WaitingListStatusEnum::Notified,
            'notified_at' => now(),
        ]);
        $hold = InventoryLedger::create([
            'trip_instance_id' => $f['instance']->id,
            'quantity' => -1,
            'type' => 'hold',
            'expires_at' => now()->subMinute(),
        ]);
        $waitingList->update(['hold_id' => $hold->id]);

        app(\App\Jobs\ReleaseWaitlistHold::class, ['holdId' => $hold->id, 'waitlistId' => $waitingList->id])->handle();

        $this->assertEquals(WaitingListStatusEnum::Expired, $waitingList->fresh()->status);
    }
}
