<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Services\CreateBookingService;
use App\Models\Tenant;
use App\Models\Customer;
use App\Models\User;
use App\Models\TripTemplate;
use App\Models\TripInstance;
use App\Models\TripPricingTier;
use App\Models\TripAddon;
use App\Exceptions\InventoryExhaustedException;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;

class CreateBookingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected CreateBookingService $service;
    protected Tenant $tenant;
    protected TripInstance $tripInstance;
    protected Customer $customer;
    protected User $adminUser;
    protected TripPricingTier $tier;
    protected TripAddon $addon;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->service = new CreateBookingService();

        // 1. Strict Multi-Tenant Environment Setup
        $this->tenant = Tenant::create(['name' => 'Test Agency', 'slug' => 'test-agency', 'domain' => 'test.zatara.com']);
        
        $this->adminUser = User::create([
            'name' => 'Admin User',
            'email' => 'admin@zatara.com',
            'phone' => '0500000000',
            'password' => bcrypt('password'),
        ]);
        
        $this->adminUser->tenants()->attach($this->tenant->id);

        $this->customer = Customer::create([
            'name' => 'John Doe',
            'phone' => '+966500000000',
            'tenant_id' => $this->tenant->id,
        ]);

        $template = TripTemplate::create([
            'tenant_id' => $this->tenant->id,
            'title' => 'Test Template',
            'base_price' => 1000.00,
        ]);

        $this->tripInstance = TripInstance::create([
            'tenant_id' => $this->tenant->id,
            'trip_template_id' => $template->id,
            'start_date' => now()->addDays(10)->toDateString(),
            'end_date' => now()->addDays(15)->toDateString(),
            'available_seats' => 10,
            'status' => 'Published',
        ]);

        $this->tier = TripPricingTier::create([
            'tenant_id' => $this->tenant->id,
            'trip_instance_id' => $this->tripInstance->id,
            'name' => 'VIP',
            'price' => 1000.00,
        ]);

        $this->addon = TripAddon::create([
            'tenant_id' => $this->tenant->id,
            'trip_instance_id' => $this->tripInstance->id,
            'name' => 'Extra Luggage',
            'price' => 250.00,
            'max_quantity' => 50,
        ]);
    }

    public function test_it_successfully_creates_a_b2c_self_checkout_booking_with_accurate_financials()
    {
        $payload = [
            'tenant_id' => $this->tenant->id,
            'trip_instance_id' => $this->tripInstance->id,
            'customer_id' => $this->customer->id,
            'passengersData' => [
                ['trip_pricing_tier_id' => $this->tier->id, 'dynamic_data' => null],
                ['trip_pricing_tier_id' => $this->tier->id, 'dynamic_data' => null],
            ],
            'addonsData' => [
                ['trip_addon_id' => $this->addon->id, 'quantity' => 2],
            ],
            'user_id' => null, // B2C self-checkout has no admin creator
            'notes' => 'Test Notes',
        ];

        $booking = $this->service->execute($payload);

        // Verify Identity Separation
        $this->assertEquals($this->customer->id, $booking->customer_id);
        $this->assertNull($booking->user_id);
        $this->assertEquals($this->tenant->id, $booking->tenant_id);

        // Verify Initial States
        $this->assertEquals(BookingStatus::Pending->value, $booking->booking_status->value ?? $booking->booking_status);
        $this->assertEquals(PaymentStatus::Unpaid->value, $booking->payment_status->value ?? $booking->payment_status);

        // Verify Financial Integrity
        // 2 Passengers * 1000 = 2000
        // 2 Addons * 250 = 500
        // Total = 2500
        $this->assertEquals(2500.00, $booking->grand_total);
        
        $this->assertCount(2, $booking->passengers);
        $this->assertCount(1, $booking->bookingAddons);
    }

    public function test_it_successfully_creates_a_b2b_admin_booking_with_audit_trail()
    {
        $payload = [
            'tenant_id' => $this->tenant->id,
            'trip_instance_id' => $this->tripInstance->id,
            'customer_id' => $this->customer->id,
            'passengersData' => [
                ['trip_pricing_tier_id' => $this->tier->id, 'dynamic_data' => null],
            ],
            'addonsData' => [],
            'user_id' => $this->adminUser->id, // B2B Admin checkout
        ];

        $booking = $this->service->execute($payload);

        // Verify Identity Separation (Audit Trail)
        $this->assertEquals($this->customer->id, $booking->customer_id); // The Owner
        $this->assertEquals($this->adminUser->id, $booking->user_id); // The Creator (Audit)
        $this->assertEquals(1000.00, $booking->grand_total);
    }

    public function test_it_throws_inventory_exhausted_exception_when_capacity_is_exceeded()
    {
        $this->expectException(InventoryExhaustedException::class);
        $this->expectExceptionMessage('Sorry, only 10 seats left.');

        // Attempt to book 11 seats when max_capacity is 10
        $passengersData = array_fill(0, 11, [
            'trip_pricing_tier_id' => $this->tier->id,
            'dynamic_data' => null,
        ]);

        $payload = [
            'tenant_id' => $this->tenant->id,
            'trip_instance_id' => $this->tripInstance->id,
            'customer_id' => $this->customer->id,
            'passengersData' => $passengersData,
        ];

        // This should throw the exception and rollback the transaction
        $this->service->execute($payload);
        
        // Assert Database was rolled back (0 bookings should exist)
        $this->assertDatabaseCount('bookings', 0);
    }
}
