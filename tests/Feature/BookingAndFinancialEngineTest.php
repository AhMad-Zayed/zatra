<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\TripTemplate;
use App\Models\TripInstance;
use App\Models\Booking;
use App\Models\Payment;
use App\Enums\BookingStatus;
use App\Enums\PaymentType;
use App\Services\BookingService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingAndFinancialEngineTest extends TestCase
{
    use RefreshDatabase;

    private BookingService $bookingService;
    private PaymentService $paymentService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bookingService = new BookingService();
        $this->paymentService = new PaymentService();
    }

    public function test_capacity_enforcement(): void
    {
        $tenant = Tenant::create(['name' => 'Agency North']);
        $customer = User::create(['name' => 'John Customer', 'phone' => '0799999999']);
        
        $template = TripTemplate::create([
            'tenant_id' => $tenant->id,
            'title' => 'Dead Sea Trip',
            'base_price' => 50.00,
        ]);

        $instance = TripInstance::create([
            'tenant_id' => $tenant->id,
            'trip_template_id' => $template->id,
            'start_date' => now()->addDays(1),
            'end_date' => now()->addDays(1),
            'available_seats' => 2,
            'status' => 'active',
        ]);

        $passengers = [
            ['name' => 'Passenger 1', 'passport_number' => 'A1234567'],
            ['name' => 'Passenger 2', 'passport_number' => 'B1234568'],
        ];

        // 1. First booking with 2 passengers should succeed
        $booking = $this->bookingService->createBooking($instance, $customer, $passengers, 100.00);
        $this->assertDatabaseHas('bookings', ['id' => $booking->id]);

        // 2. Second booking with 1 passenger should fail capacity check
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is at capacity');
        
        $this->bookingService->createBooking($instance, $customer, [
            ['name' => 'Passenger 3', 'passport_number' => 'C1234569']
        ], 50.00);
    }

    public function test_reference_generation(): void
    {
        $tenant = Tenant::create(['name' => 'Zatara Travel Agency']);
        $customer = User::create(['name' => 'John Customer', 'phone' => '0799999999']);
        
        $template = TripTemplate::create([
            'tenant_id' => $tenant->id,
            'title' => 'Amman City Tour',
            'base_price' => 30.00,
        ]);

        $instance = TripInstance::create([
            'tenant_id' => $tenant->id,
            'trip_template_id' => $template->id,
            'start_date' => now()->addDays(1),
            'end_date' => now()->addDays(1),
            'available_seats' => 10,
            'status' => 'active',
        ]);

        $booking = $this->bookingService->createBooking($instance, $customer, [
            ['name' => 'Passenger 1', 'passport_number' => 'A1234567']
        ], 30.00);

        // Name is "Zatara Travel Agency", slug prefix should be ZAT
        // Format: ZAT-26-00001
        $year = now()->format('y');
        $this->assertEquals("ZAT-{$year}-00001", $booking->reference);
    }

    public function test_payment_immutability(): void
    {
        $tenant = Tenant::create(['name' => 'Agency']);
        $customer = User::create(['name' => 'John', 'phone' => '0799999999']);
        $agent = User::create(['name' => 'Agent Sam', 'phone' => '0791111111']);
        
        $template = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'Tour', 'base_price' => 10.00]);
        $instance = TripInstance::create([
            'tenant_id' => $tenant->id,
            'trip_template_id' => $template->id,
            'start_date' => now()->addDays(1),
            'end_date' => now()->addDays(1),
            'available_seats' => 10,
            'status' => 'active',
        ]);

        $booking = $this->bookingService->createBooking($instance, $customer, [
            ['name' => 'Passenger 1', 'passport_number' => 'A1234567']
        ], 100.00);

        $payment = $this->paymentService->recordPayment($booking, 50.00, 'cash', $agent, PaymentType::DEPOSIT);

        // Verify update throws exception
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Payment records are immutable');
        $payment->update(['amount' => 60.00]);
    }

    public function test_payment_deletion_fails(): void
    {
        $tenant = Tenant::create(['name' => 'Agency']);
        $customer = User::create(['name' => 'John', 'phone' => '0799999999']);
        $agent = User::create(['name' => 'Agent Sam', 'phone' => '0791111111']);
        
        $template = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'Tour', 'base_price' => 10.00]);
        $instance = TripInstance::create([
            'tenant_id' => $tenant->id,
            'trip_template_id' => $template->id,
            'start_date' => now()->addDays(1),
            'end_date' => now()->addDays(1),
            'available_seats' => 10,
            'status' => 'active',
        ]);

        $booking = $this->bookingService->createBooking($instance, $customer, [
            ['name' => 'Passenger 1', 'passport_number' => 'A1234567']
        ], 100.00);

        $payment = $this->paymentService->recordPayment($booking, 50.00, 'cash', $agent, PaymentType::DEPOSIT);

        // Verify delete throws exception
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Payment records cannot be deleted');
        $payment->delete();
    }

    public function test_booking_financial_status_recalculation_and_reversal(): void
    {
        $tenant = Tenant::create(['name' => 'Agency']);
        $customer = User::create(['name' => 'John', 'phone' => '0799999999']);
        $agent = User::create(['name' => 'Agent Sam', 'phone' => '0791111111']);
        
        $template = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'Tour', 'base_price' => 100.00]);
        $instance = TripInstance::create([
            'tenant_id' => $tenant->id,
            'trip_template_id' => $template->id,
            'start_date' => now()->addDays(1),
            'end_date' => now()->addDays(1),
            'available_seats' => 10,
            'status' => 'active',
        ]);

        // Booking total is 100.00
        $booking = $this->bookingService->createBooking($instance, $customer, [
            ['name' => 'Passenger 1', 'passport_number' => 'A1234567']
        ], 100.00);

        // Initially status should be PENDING
        $this->assertEquals(BookingStatus::PENDING, $booking->status);
        $this->assertEquals(0.00, $booking->paid_amount);
        $this->assertEquals(100.00, $booking->remaining_amount);

        // 1. Pay 40.00 (partial payment)
        $payment1 = $this->paymentService->recordPayment($booking, 40.00, 'cash', $agent, PaymentType::DEPOSIT);
        $booking->refresh();
        $this->assertEquals(BookingStatus::PARTIAL, $booking->status);
        $this->assertEquals(40.00, $booking->paid_amount);
        $this->assertEquals(60.00, $booking->remaining_amount);

        // 2. Pay remaining 60.00 (full payment)
        $payment2 = $this->paymentService->recordPayment($booking, 60.00, 'visa', $agent, PaymentType::INSTALLMENT);
        $booking->refresh();
        $this->assertEquals(BookingStatus::PAID, $booking->status);
        $this->assertEquals(100.00, $booking->paid_amount);
        $this->assertEquals(0.00, $booking->remaining_amount);

        // 3. Reverse the first payment of 40.00
        $reversal = $this->paymentService->reversePayment($payment1, 'Customer request refund', $agent);
        $booking->refresh();
        // Paid amount is now 100.00 - 40.00 = 60.00 (which is partial)
        $this->assertEquals(60.00, $booking->paid_amount);
        $this->assertEquals(40.00, $booking->remaining_amount);
        $this->assertEquals(BookingStatus::PARTIAL, $booking->status);
    }
}
