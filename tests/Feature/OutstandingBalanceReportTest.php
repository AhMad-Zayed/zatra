<?php

namespace Tests\Feature;

use App\Enums\PaymentType;
use App\Filament\Clusters\ReportsCenter\Pages\OutstandingBalanceReport;
use App\Filament\Clusters\ReportsCenter\Pages\RefundPendingReport;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\TripInstance;
use App\Models\TripPassengerCategory;
use App\Models\TripTemplate;
use App\Models\User;
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
 * Regression coverage for Reports Center Ticket 2 (Outstanding Balance + RefundPending).
 * Read-only: all state transitions go through the same real BookingService/CreateBookingService
 * calls every other test in this app already uses to build realistic fixtures.
 */
class OutstandingBalanceReportTest extends TestCase
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
     * @return array{tenant: Tenant, admin: User, customer: Customer, template: TripTemplate, instance: TripInstance, cat: TripPassengerCategory}
     */
    private function makeFixture(string $suffix, string $currency = 'USD', float $price = 200.00): array
    {
        $tenant = Tenant::create(['name' => "Agency {$suffix}", 'slug' => "agency-ob-{$suffix}", 'domain' => "{$suffix}.zatara.com"]);
        $admin = $this->makeAgencyAdmin($tenant, "0794{$suffix}");
        $customer = Customer::create(['name' => 'Jane', 'phone' => "0591{$suffix}", 'tenant_id' => $tenant->id]);
        $template = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'Tour', 'base_price' => $price, 'currency' => $currency]);
        $instance = TripInstance::create([
            'tenant_id' => $tenant->id,
            'trip_template_id' => $template->id,
            'start_date' => now()->addDays(5),
            'end_date' => now()->addDays(10),
            'available_seats' => 20,
            'status' => 'active',
            'currency' => $currency,
        ]);
        $cat = TripPassengerCategory::create([
            'tenant_id' => $tenant->id, 'trip_instance_id' => $instance->id,
            'name' => 'Adult', 'price' => $price, 'requires_seat' => true,
        ]);

        return compact('tenant', 'admin', 'customer', 'template', 'instance', 'cat');
    }

    private function makeBooking(array $f): \App\Models\Booking
    {
        return $this->createBookingService->execute([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'customer_id' => $f['customer']->id,
            'passengersData' => [
                ['trip_passenger_category_id' => $f['cat']->id, 'first_name' => 'P', 'last_name' => '1'],
            ],
        ]);
    }

    private function loadReport(array $f, string $pageClass = OutstandingBalanceReport::class): \Livewire\Features\SupportTesting\Testable
    {
        $this->actingAs($f['admin']);
        Filament::setTenant($f['tenant'], true);

        return Livewire::test($pageClass);
    }

    // ------------------------------------------------------------------
    // Inclusion / exclusion by balance_due / payment_status
    // ------------------------------------------------------------------

    public function test_report_includes_a_partially_paid_booking_with_positive_balance_due(): void
    {
        $f = $this->makeFixture('001');
        $booking = $this->makeBooking($f);
        $this->bookingService->recordPayment($booking, 50.00, 'cash', $f['admin'], PaymentType::DEPOSIT);

        $this->assertGreaterThan(0, $booking->fresh()->balance_due);

        $this->loadReport($f)->assertCanSeeTableRecords([$booking->fresh()]);
    }

    public function test_report_excludes_a_fully_paid_booking(): void
    {
        $f = $this->makeFixture('002');
        $booking = $this->makeBooking($f);
        $this->bookingService->recordPayment($booking, 200.00, 'cash', $f['admin'], PaymentType::FULL);

        $this->assertSame(0.0, (float) $booking->fresh()->balance_due);
        $this->assertTrue($booking->fresh()->payment_status === \App\Enums\PaymentStatus::Paid);

        $this->loadReport($f)->assertCanNotSeeTableRecords([$booking->fresh()]);
    }

    public function test_report_includes_a_refund_pending_booking_even_though_balance_due_is_zero(): void
    {
        $f = $this->makeFixture('003');
        $booking = $this->makeBooking($f);
        $this->bookingService->recordPayment($booking, 200.00, 'cash', $f['admin'], PaymentType::FULL);
        $this->bookingService->cancelBooking($booking, 'customer request');

        $booking->refresh();
        $this->assertTrue($booking->payment_status === \App\Enums\PaymentStatus::RefundPending);
        $this->assertSame(0.0, (float) $booking->balance_due, 'Cancellation clamps balance_due to zero, yet this booking must still surface via the payment_status clause.');

        $this->loadReport($f)->assertCanSeeTableRecords([$booking]);
    }

    // ------------------------------------------------------------------
    // Currency grouping correctness — never sum across currencies
    // ------------------------------------------------------------------

    public function test_currency_totals_never_sum_across_currencies(): void
    {
        $f = $this->makeFixture('004', currency: 'USD', price: 200.00);
        $bookingUsd = $this->makeBooking($f);
        $this->bookingService->recordPayment($bookingUsd, 50.00, 'cash', $f['admin'], PaymentType::DEPOSIT);

        // Second template/instance on the SAME tenant, different currency.
        $ilsTemplate = TripTemplate::create(['tenant_id' => $f['tenant']->id, 'title' => 'ILS Tour', 'base_price' => 300.00, 'currency' => 'ILS']);
        $ilsInstance = TripInstance::create([
            'tenant_id' => $f['tenant']->id, 'trip_template_id' => $ilsTemplate->id,
            'start_date' => now()->addDays(5), 'end_date' => now()->addDays(10),
            'available_seats' => 20, 'status' => 'active', 'currency' => 'ILS',
        ]);
        $ilsCat = TripPassengerCategory::create(['tenant_id' => $f['tenant']->id, 'trip_instance_id' => $ilsInstance->id, 'name' => 'Adult', 'price' => 300.00, 'requires_seat' => true]);
        $bookingIls = $this->createBookingService->execute([
            'tenant_id' => $f['tenant']->id, 'trip_instance_id' => $ilsInstance->id, 'customer_id' => $f['customer']->id,
            'passengersData' => [['trip_passenger_category_id' => $ilsCat->id, 'first_name' => 'P', 'last_name' => '2']],
        ]);
        $this->bookingService->recordPayment($bookingIls, 100.00, 'cash', $f['admin'], PaymentType::DEPOSIT);

        $component = $this->loadReport($f);
        $totals = $component->instance()->currencyTotals()->keyBy('currency');

        $this->assertEqualsWithDelta(150.00, $totals['USD']['total'], 0.01, 'USD balance: 200 - 50 = 150.');
        $this->assertEqualsWithDelta(200.00, $totals['ILS']['total'], 0.01, 'ILS balance: 300 - 100 = 200.');
        $this->assertCount(2, $totals, 'Exactly one total per currency — never one figure mixing both.');
    }

    // ------------------------------------------------------------------
    // Days outstanding
    // ------------------------------------------------------------------

    public function test_days_outstanding_is_calculated_from_created_at(): void
    {
        $f = $this->makeFixture('005');
        $booking = $this->makeBooking($f);
        $this->bookingService->recordPayment($booking, 50.00, 'cash', $f['admin'], PaymentType::DEPOSIT);
        \App\Models\Booking::where('id', $booking->id)->update(['created_at' => now()->subDays(7)]);

        $component = $this->loadReport($f);
        $records = $component->instance()->getTableRecords();
        $record = $records->firstWhere('id', $booking->id);

        $this->assertNotNull($record);
        $this->assertSame(7, (int) $record->created_at->diffInDays(now()));
    }

    // ------------------------------------------------------------------
    // RefundPending view
    // ------------------------------------------------------------------

    public function test_refund_pending_view_shows_only_refund_pending_bookings(): void
    {
        $f = $this->makeFixture('006');
        $refundBooking = $this->makeBooking($f);
        $this->bookingService->recordPayment($refundBooking, 200.00, 'cash', $f['admin'], PaymentType::FULL);
        $this->bookingService->cancelBooking($refundBooking, 'customer request');

        $partiallyPaidBooking = $this->makeBooking($f);
        $this->bookingService->recordPayment($partiallyPaidBooking, 50.00, 'cash', $f['admin'], PaymentType::DEPOSIT);

        $component = $this->loadReport($f, RefundPendingReport::class);

        $component->assertCanSeeTableRecords([$refundBooking->fresh()]);
        $component->assertCanNotSeeTableRecords([$partiallyPaidBooking->fresh()]);
    }

    public function test_refund_pending_since_days_uses_updated_at(): void
    {
        $f = $this->makeFixture('007');
        $booking = $this->makeBooking($f);
        $this->bookingService->recordPayment($booking, 200.00, 'cash', $f['admin'], PaymentType::FULL);
        $this->bookingService->cancelBooking($booking, 'customer request');
        \App\Models\Booking::where('id', $booking->id)->update(['updated_at' => now()->subDays(3)]);

        $component = $this->loadReport($f, RefundPendingReport::class);
        $records = $component->instance()->getTableRecords();
        $record = $records->firstWhere('id', $booking->id);

        $this->assertNotNull($record);
        $this->assertSame(3, (int) $record->updated_at->diffInDays(now()));
    }

    // ------------------------------------------------------------------
    // Tenant isolation
    // ------------------------------------------------------------------

    public function test_outstanding_balance_report_does_not_leak_another_tenants_bookings(): void
    {
        $fA = $this->makeFixture('008a');
        $fB = $this->makeFixture('008b');
        $bookingA = $this->makeBooking($fA);
        $this->bookingService->recordPayment($bookingA, 50.00, 'cash', $fA['admin'], PaymentType::DEPOSIT);
        $bookingB = $this->makeBooking($fB);
        $this->bookingService->recordPayment($bookingB, 50.00, 'cash', $fB['admin'], PaymentType::DEPOSIT);

        $this->loadReport($fA)
            ->assertCanSeeTableRecords([$bookingA->fresh()])
            ->assertCanNotSeeTableRecords([$bookingB->fresh()]);
    }

    public function test_refund_pending_report_does_not_leak_another_tenants_bookings(): void
    {
        $fA = $this->makeFixture('009a');
        $fB = $this->makeFixture('009b');
        $bookingA = $this->makeBooking($fA);
        $this->bookingService->recordPayment($bookingA, 200.00, 'cash', $fA['admin'], PaymentType::FULL);
        $this->bookingService->cancelBooking($bookingA, 'x');
        $bookingB = $this->makeBooking($fB);
        $this->bookingService->recordPayment($bookingB, 200.00, 'cash', $fB['admin'], PaymentType::FULL);
        $this->bookingService->cancelBooking($bookingB, 'x');

        $this->loadReport($fA, RefundPendingReport::class)
            ->assertCanSeeTableRecords([$bookingA->fresh()])
            ->assertCanNotSeeTableRecords([$bookingB->fresh()]);
    }

    // ------------------------------------------------------------------
    // Excel export
    // ------------------------------------------------------------------

    public function test_outstanding_balance_export_runs_without_error(): void
    {
        $f = $this->makeFixture('010');
        $booking = $this->makeBooking($f);
        $this->bookingService->recordPayment($booking, 50.00, 'cash', $f['admin'], PaymentType::DEPOSIT);

        $component = $this->loadReport($f);
        $component->callTableAction('export');
        $component->assertHasNoTableActionErrors();
    }

    public function test_refund_pending_export_runs_without_error(): void
    {
        $f = $this->makeFixture('011');
        $booking = $this->makeBooking($f);
        $this->bookingService->recordPayment($booking, 200.00, 'cash', $f['admin'], PaymentType::FULL);
        $this->bookingService->cancelBooking($booking, 'x');

        $component = $this->loadReport($f, RefundPendingReport::class);
        $component->callTableAction('export');
        $component->assertHasNoTableActionErrors();
    }
}
