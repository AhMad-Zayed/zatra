<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\TripTemplate;
use App\Models\TripInstance;
use App\Models\Booking;
use App\Enums\BookingStatus;
use App\Notifications\BookingPending;
use App\Notifications\BookingConfirmed;
use App\Services\BookingService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class NotificationSystemTest extends TestCase
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

    public function test_booking_creation_triggers_pending_notification_dispatch(): void
    {
        Notification::fake();

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
            'available_seats' => 10,
            'status' => 'active',
        ]);

        $booking = $this->bookingService->createBooking($instance, $customer, [
            ['name' => 'Passenger 1', 'passport_number' => 'A1234567']
        ], 50.00);

        // Assert BookingPending notification was sent to customer
        Notification::assertSentTo(
            $customer,
            BookingPending::class,
            fn ($notification) => $notification->booking->id === $booking->id
        );
    }

    public function test_booking_full_payment_triggers_confirmed_notification_dispatch(): void
    {
        Notification::fake();

        $tenant = Tenant::create(['name' => 'Agency North']);
        $customer = User::create(['name' => 'John Customer', 'phone' => '0799999999']);
        $agent = User::create(['name' => 'Agent Sam', 'phone' => '0791111111']);
        
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
            'available_seats' => 10,
            'status' => 'active',
        ]);

        $booking = $this->bookingService->createBooking($instance, $customer, [
            ['name' => 'Passenger 1', 'passport_number' => 'A1234567']
        ], 50.00);

        // Record full payment
        $this->paymentService->recordPayment($booking, 50.00, 'cash', $agent, \App\Enums\PaymentType::FULL);

        // Assert BookingConfirmed notification was sent to customer
        Notification::assertSentTo(
            $customer,
            BookingConfirmed::class,
            fn ($notification) => $notification->booking->id === $booking->id
        );
    }

    public function test_whatsapp_channel_logging(): void
    {
        // Don't fake notification to test the actual channel send() method
        Log::shouldReceive('info')
            ->once()
            ->withArgs(fn ($message) => 
                str_contains($message, 'WhatsApp Sent to 0799999999') && 
                str_contains($message, 'booking_pending')
            );

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
            'available_seats' => 10,
            'status' => 'active',
        ]);

        // This will trigger the observer created event, which calls notify() and triggers WhatsAppChannel::send()
        $this->bookingService->createBooking($instance, $customer, [
            ['name' => 'Passenger 1', 'passport_number' => 'A1234567']
        ], 50.00);
    }
}
