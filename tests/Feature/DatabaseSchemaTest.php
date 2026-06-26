<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Passenger;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\TripInstance;
use App\Models\TripTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_creation_and_soft_delete(): void
    {
        $tenant = Tenant::create([
            'name' => 'Agency 1',
            'domain' => 'agency1.com',
            'is_visa_enabled' => true,
        ]);

        $this->assertDatabaseHas('tenants', [
            'name' => 'Agency 1',
            'domain' => 'agency1.com',
            'is_visa_enabled' => 1,
        ]);

        $tenant->delete();
        $this->assertSoftDeleted($tenant);
    }

    public function test_user_creation_with_phone_and_nullable_password(): void
    {
        $user = User::create([
            'name' => 'John Customer',
            'phone' => '0799999999',
            'email' => 'john@customer.com',
            'password' => null,
        ]);

        $this->assertDatabaseHas('users', [
            'name' => 'John Customer',
            'phone' => '0799999999',
            'email' => 'john@customer.com',
            'password' => null,
        ]);
    }

    public function test_trip_relations(): void
    {
        $tenant = Tenant::create(['name' => 'Agency 1']);

        $template = TripTemplate::create([
            'tenant_id' => $tenant->id,
            'title' => 'Wadi Rum Weekend',
            'base_price' => 150.00,
        ]);

        $instance = TripInstance::create([
            'tenant_id' => $tenant->id,
            'trip_template_id' => $template->id,
            'start_date' => now()->addDays(5),
            'end_date' => now()->addDays(7),
            'available_seats' => 20,
            'status' => 'active',
        ]);

        $this->assertEquals($template->id, $instance->tripTemplate->id);
        $this->assertEquals(150.00, $template->base_price);
        $this->assertEquals('active', $instance->status);
    }

    public function test_booking_with_international_extras_and_paid_amount_accessor(): void
    {
        $tenant = Tenant::create(['name' => 'Agency 1']);
        $user = User::create(['name' => 'Agent', 'phone' => '0791111111']);
        $customer = User::create(['name' => 'John', 'phone' => '0792222222']);

        $template = TripTemplate::create([
            'tenant_id' => $tenant->id,
            'title' => 'Aqaba Getaway',
            'base_price' => 100.00,
        ]);

        $instance = TripInstance::create([
            'tenant_id' => $tenant->id,
            'trip_template_id' => $template->id,
            'start_date' => now()->addDays(1),
            'end_date' => now()->addDays(2),
            'available_seats' => 10,
            'status' => 'active',
        ]);

        // Create booking with international optional details
        $booking = Booking::create([
            'tenant_id' => $tenant->id,
            'user_id' => $customer->id,
            'trip_instance_id' => $instance->id,
            'reference' => 'ZAT-26-00001',
            'status' => 'pending',
            'total_amount' => 500.00,
            'flight_details' => 'RJ-301 to Amman, flight tickets arranged.',
            'hotel_details' => 'Movenpick Hotel Aqaba, 3 nights.',
            'insurance_details' => 'GIG Travel Insurance Policy #12345.',
            'visa_details' => 'Visa pre-approved.',
        ]);

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'flight_details' => 'RJ-301 to Amman, flight tickets arranged.',
            'hotel_details' => 'Movenpick Hotel Aqaba, 3 nights.',
            'insurance_details' => 'GIG Travel Insurance Policy #12345.',
            'visa_details' => 'Visa pre-approved.',
        ]);

        // Initially paid amount should be 0.00
        $this->assertEquals(0.00, $booking->paid_amount);

        // Record a payment
        Payment::create([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'amount' => 300.00,
            'payment_method' => 'visa',
            'received_by' => $user->id,
            'type' => 'payment',
        ]);

        // Record a reversal (negative payment)
        Payment::create([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'amount' => -100.00,
            'payment_method' => 'visa',
            'received_by' => $user->id,
            'type' => 'reversal',
        ]);

        // Refresh model and verify paid_amount is 200.00 (300.00 - 100.00)
        $booking->refresh();
        $this->assertEquals(200.00, $booking->paid_amount);
    }

    public function test_passenger_media_collections(): void
    {
        $tenant = Tenant::create(['name' => 'Agency 1']);
        $customer = User::create(['name' => 'John', 'phone' => '0792222222']);
        $template = TripTemplate::create([
            'tenant_id' => $tenant->id,
            'title' => 'Aqaba Getaway',
            'base_price' => 100.00,
        ]);
        $instance = TripInstance::create([
            'tenant_id' => $tenant->id,
            'trip_template_id' => $template->id,
            'start_date' => now()->addDays(1),
            'end_date' => now()->addDays(2),
            'available_seats' => 10,
            'status' => 'active',
        ]);
        $booking = Booking::create([
            'tenant_id' => $tenant->id,
            'user_id' => $customer->id,
            'trip_instance_id' => $instance->id,
            'reference' => 'ZAT-26-00002',
            'status' => 'pending',
            'total_amount' => 100.00,
        ]);

        $passenger = Passenger::create([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'name' => 'Tareq Passenger',
            'passport_number' => 'P12345678',
        ]);

        $this->assertNotNull($passenger->getMediaCollection('passport'));
        $this->assertNotNull($passenger->getMediaCollection('national_id'));
    }
}
