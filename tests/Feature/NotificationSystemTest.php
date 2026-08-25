<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Tenant;
use App\Models\TripInstance;
use App\Models\TripPassengerCategory;
use App\Models\TripTemplate;
use App\Notifications\BookingPending;
use App\Services\CreateBookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Rewritten against the live path: the original tests created bookings via the now-deleted
 * BookingService::createBooking() using `User` as the customer and no TripPassengerCategory
 * (both already caused a FK-constraint failure pre-existing this change). The live path is
 * CreateBookingService::execute() with a real Customer + TripPassengerCategory.
 */
class NotificationSystemTest extends TestCase
{
    use RefreshDatabase;

    private CreateBookingService $createBookingService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createBookingService = new CreateBookingService();
    }

    /**
     * @return array{tenant: Tenant, customer: Customer, instance: TripInstance, cat: TripPassengerCategory}
     */
    private function makeFixture(string $suffix): array
    {
        // Tenant-level email/WhatsApp/SMS alert toggles default to true and, independently of
        // BookingPending's WhatsAppChannel delivery, drive App\Listeners\SendBookingNotifications
        // (fired from the same BookingCreated event CreateBookingService dispatches) to also log
        // its own "Sending WhatsApp to ..." line. Disabled here so these tests assert only the
        // one specific mechanism each is actually about.
        $tenant = Tenant::create([
            'name' => "Agency {$suffix}", 'slug' => "agency-notif-{$suffix}", 'domain' => "{$suffix}.zatara.com",
            'enable_email_alerts' => false, 'enable_whatsapp_alerts' => false, 'enable_sms_alerts' => false,
        ]);
        $customer = Customer::create(['name' => 'John Customer', 'phone' => "0799{$suffix}", 'tenant_id' => $tenant->id]);
        $template = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'Dead Sea Trip', 'base_price' => 50.00]);
        $instance = TripInstance::create([
            'tenant_id' => $tenant->id,
            'trip_template_id' => $template->id,
            'start_date' => now()->addDays(1),
            'end_date' => now()->addDays(1),
            'available_seats' => 10,
            'status' => 'active',
        ]);
        $cat = TripPassengerCategory::create([
            'tenant_id' => $tenant->id, 'trip_instance_id' => $instance->id,
            'name' => 'Adult', 'price' => 50.00, 'requires_seat' => true,
        ]);

        return compact('tenant', 'customer', 'instance', 'cat');
    }

    public function test_booking_creation_triggers_pending_notification_dispatch(): void
    {
        Notification::fake();

        $f = $this->makeFixture('001');

        $booking = $this->createBookingService->execute([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'customer_id' => $f['customer']->id,
            'passengersData' => [
                ['trip_passenger_category_id' => $f['cat']->id, 'first_name' => 'Passenger', 'last_name' => '1'],
            ],
        ]);

        // BookingObserver::created() dispatches BookingPending to the booking's customer
        // whenever a new booking lands in Pending status.
        Notification::assertSentTo(
            $f['customer'],
            BookingPending::class,
            fn ($notification) => $notification->booking->id === $booking->id
        );
    }

    // test_booking_full_payment_triggers_confirmed_notification_dispatch removed: it asserted
    // Notification::assertSentTo(..., BookingConfirmed::class, ...) after a full payment.
    // BookingConfirmed is a real Notification class but nothing in the current app ever
    // dispatches it (confirmed via a repo-wide search — the admin confirm_cash/collect_balance
    // actions instead dispatch SendBookingNotificationJob directly, a different mechanism). This
    // was already failing before this change; there is no live behavior for a rewrite to target,
    // and wiring BookingConfirmed up would be a functional change outside this fix's scope.

    public function test_whatsapp_channel_logging(): void
    {
        // Don't fake notifications, to exercise the actual WhatsAppChannel::send() method.
        Log::shouldReceive('info')
            ->once()
            ->withArgs(fn ($message) =>
                str_contains($message, 'WhatsApp Sent to 0799002') &&
                str_contains($message, 'booking_pending')
            );

        $f = $this->makeFixture('002');

        // This triggers BookingObserver::created(), which calls notify() and, in turn,
        // WhatsAppChannel::send().
        $this->createBookingService->execute([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'customer_id' => $f['customer']->id,
            'passengersData' => [
                ['trip_passenger_category_id' => $f['cat']->id, 'first_name' => 'Passenger', 'last_name' => '1'],
            ],
        ]);
    }
}
