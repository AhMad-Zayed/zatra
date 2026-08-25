<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Jobs\SendBookingNotificationJob;
use App\Models\Customer;
use App\Models\Passenger;
use App\Models\Tenant;
use App\Models\TripInstance;
use App\Models\TripPassengerCategory;
use App\Models\TripTemplate;
use App\Services\CreateBookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Regression coverage for migrating ReleaseExpiredBookings to delegate to
 * BookingService::cancelBooking() instead of a raw ->update() + hand-rolled notification
 * block. Confirms the command now gets, for free, everything the old manual code was
 * missing (passenger soft-delete, activity-log audit trail, payment_status tracking) while
 * still sending its own timeout-specific customer notification and preserving the P0-5
 * idempotency guarantee against overlapping/repeated runs.
 */
class ReleaseExpiredBookingsMigrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{tenant: Tenant, customer: Customer, instance: TripInstance, cat: TripPassengerCategory, booking: \App\Models\Booking, passengerId: int}
     */
    private function makeExpiredUnpaidBooking(string $suffix): array
    {
        $tenant = Tenant::create(['name' => "Agency {$suffix}", 'slug' => "agency-rem-{$suffix}", 'domain' => "{$suffix}.zatara.com"]);
        $customer = Customer::create(['name' => 'Jane', 'phone' => "0593{$suffix}", 'email' => "jane{$suffix}@example.com", 'tenant_id' => $tenant->id]);
        $template = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'Tour', 'base_price' => 100]);
        $instance = TripInstance::create([
            'tenant_id' => $tenant->id,
            'trip_template_id' => $template->id,
            'start_date' => now()->addDays(5),
            'end_date' => now()->addDays(10),
            'available_seats' => 10,
            'status' => 'active',
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

        $passengerId = $booking->passengers()->first()->id;

        // Make it eligible: Pending/Unpaid (CreateBookingService's default for a full-payment,
        // no-deposit booking) with an expiry in the past.
        $booking->update(['expires_at' => now()->subMinute()]);
        $this->assertEquals(BookingStatus::Pending, $booking->fresh()->booking_status);
        $this->assertEquals(PaymentStatus::Unpaid, $booking->fresh()->payment_status);

        return compact('tenant', 'customer', 'instance', 'cat', 'booking', 'passengerId');
    }

    public function test_release_expired_bookings_releases_inventory_soft_deletes_passengers_and_logs_activity(): void
    {
        $f = $this->makeExpiredUnpaidBooking('a');
        Queue::fake();

        $seatsBefore = $f['instance']->fresh()->getRemainingSeatsAttribute();

        $this->artisan('bookings:release-expired')->assertExitCode(0);

        $fresh = $f['booking']->fresh();
        $this->assertEquals(BookingStatus::Cancelled, $fresh->booking_status);

        // Inventory released — remaining seats increase by 1 (the seat this booking held).
        $this->assertEquals($seatsBefore + 1, $f['instance']->fresh()->getRemainingSeatsAttribute());

        // Passenger soft-deleted with cancellation annotations (cancelBooking()'s behavior,
        // which the old manual raw-update code never performed).
        $this->assertNull(Passenger::find($f['passengerId']), 'Passenger should be soft-deleted (excluded from default queries).');
        $trashedPassenger = Passenger::withTrashed()->find($f['passengerId']);
        $this->assertNotNull($trashedPassenger);
        $this->assertNotNull($trashedPassenger->deleted_at);
        $this->assertArrayHasKey('cancelled_at', $trashedPassenger->extra_preferences ?? []);

        // Activity-log audit trail entry exists (also previously missing — the old code only
        // wrote to the plain Laravel log file, invisible to the admin panel's Activity Log).
        $activityExists = Activity::where('subject_type', \App\Models\Booking::class)
            ->where('subject_id', $fresh->id)
            ->where('description', 'booking_cancelled')
            ->exists();
        $this->assertTrue($activityExists, 'cancelBooking() must leave an activity() audit-log entry for this cancellation.');

        // Unpaid stays Unpaid — this command's own selection query only ever picks up
        // payment_status = Unpaid bookings (->where('payment_status', PaymentStatus::Unpaid)),
        // so the "RefundPending for a previously-paid booking" branch of cancelBooking()'s
        // payment_status logic is structurally unreachable through this specific command; that
        // branch is already covered directly against cancelBooking() in
        // TripCancellationTest::test_cancel_booking_sets_refund_pending_for_previously_paid_booking_without_touching_amounts.
        $this->assertEquals(PaymentStatus::Unpaid, $fresh->payment_status);

        // The timeout-specific notification is still sent, distinct from cancelBooking()'s
        // own generic BookingCancelled notification.
        Queue::assertPushed(SendBookingNotificationJob::class, function ($job) use ($fresh) {
            return $job->booking->id === $fresh->id
                && $job->channel === 'whatsapp'
                && str_contains($job->message, 'انتهاء مهلة الدفع المحددة');
        });
        Queue::assertPushed(SendBookingNotificationJob::class, function ($job) use ($fresh) {
            return $job->booking->id === $fresh->id
                && $job->channel === 'email'
                && str_contains($job->message, 'انتهاء مهلة الدفع المحددة');
        });
    }

    public function test_release_expired_bookings_repeated_execution_still_protected_after_migration(): void
    {
        // Re-asserts the exact P0-5 guarantee (overlapping/repeated runs process a given
        // expired booking exactly once) still holds now that the inner mutation delegates to
        // cancelBooking() instead of a raw update — the outer lock+recheck in this command is
        // what makes that guarantee possible, and it was preserved, not removed, by this
        // migration.
        $f = $this->makeExpiredUnpaidBooking('b');
        Queue::fake();

        $this->artisan('bookings:release-expired')->assertExitCode(0);
        $this->artisan('bookings:release-expired')->assertExitCode(0);

        $this->assertEquals(BookingStatus::Cancelled, $f['booking']->fresh()->booking_status);

        Queue::assertPushed(SendBookingNotificationJob::class, 2); // whatsapp + email, exactly once each — not 4.

        $activityCount = Activity::where('subject_type', \App\Models\Booking::class)
            ->where('subject_id', $f['booking']->id)
            ->where('description', 'booking_cancelled')
            ->count();
        $this->assertSame(1, $activityCount, 'A repeated command run must not create a second cancellation audit-log entry.');
    }
}
