<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Exceptions\InsufficientSeatsException;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\InventoryLedger;
use App\Models\Tenant;
use App\Models\TripInstance;
use App\Models\TripPassengerCategory;
use App\Models\TripTemplate;
use App\Services\BookingService;
use App\Services\CreateBookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regression coverage for BookingService::transferBooking() - the single centralized
 * authority replacing the two independently hand-rolled "transfer_booking" admin action
 * bodies previously duplicated in BookingResource.php (table row action) and
 * ViewBooking.php (header action, which used the invalid ledger enum literal
 * 'cancellation' instead of 'cancelled' and crashed under MySQL strict mode).
 */
class TransferBookingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{tenant: Tenant, customer: Customer, oldInstance: TripInstance,
     *   oldCat: TripPassengerCategory, newInstance: TripInstance, newCat: TripPassengerCategory,
     *   booking: Booking, p1: int, p2: int}
     */
    private function makeTransferFixture(string $suffix, int $oldSeats = 10, int $newSeats = 10, string $newStatus = 'active'): array
    {
        $tenant = Tenant::create(['name' => "Agency {$suffix}", 'slug' => "agency-tr-{$suffix}", 'domain' => "{$suffix}.zatara.com"]);
        $customer = Customer::create(['name' => 'Jane', 'phone' => "0590{$suffix}", 'tenant_id' => $tenant->id]);

        $oldTemplate = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'Old Tour', 'base_price' => 100]);
        $oldInstance = TripInstance::create([
            'tenant_id' => $tenant->id,
            'trip_template_id' => $oldTemplate->id,
            'start_date' => now()->addDays(5),
            'end_date' => now()->addDays(10),
            'available_seats' => $oldSeats,
            'status' => 'active',
        ]);
        $oldCat = TripPassengerCategory::create([
            'tenant_id' => $tenant->id, 'trip_instance_id' => $oldInstance->id,
            'name' => 'Adult', 'price' => 100.00, 'requires_seat' => true,
        ]);

        $newTemplate = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'New Tour', 'base_price' => 150]);
        $newInstance = TripInstance::create([
            'tenant_id' => $tenant->id,
            'trip_template_id' => $newTemplate->id,
            'start_date' => now()->addDays(20),
            'end_date' => now()->addDays(25),
            'available_seats' => $newSeats,
            'status' => $newStatus,
        ]);
        $newCat = TripPassengerCategory::create([
            'tenant_id' => $tenant->id, 'trip_instance_id' => $newInstance->id,
            'name' => 'Adult', 'price' => 150.00, 'requires_seat' => true,
        ]);

        $booking = (new CreateBookingService())->execute([
            'tenant_id' => $tenant->id,
            'trip_instance_id' => $oldInstance->id,
            'customer_id' => $customer->id,
            'passengersData' => [
                ['trip_passenger_category_id' => $oldCat->id, 'first_name' => 'P1', 'last_name' => 'Test'],
                ['trip_passenger_category_id' => $oldCat->id, 'first_name' => 'P2', 'last_name' => 'Test'],
            ],
        ]);

        $passengerIds = $booking->passengers()->pluck('id')->all();

        return compact('tenant', 'customer', 'oldInstance', 'oldCat', 'newInstance', 'newCat', 'booking') + [
            'p1' => $passengerIds[0],
            'p2' => $passengerIds[1],
        ];
    }

    public function test_transfer_booking_moves_seats_updates_categories_and_totals(): void
    {
        $f = $this->makeTransferFixture('a');

        app(BookingService::class)->transferBooking(
            $f['booking'],
            $f['newInstance'],
            [$f['p1'] => $f['newCat']->id, $f['p2'] => $f['newCat']->id]
        );

        // Correct, valid enum literals - never the invalid 'cancellation' typo.
        $this->assertDatabaseHas('inventory_ledgers', [
            'trip_instance_id' => $f['oldInstance']->id,
            'booking_id' => $f['booking']->id,
            'type' => 'cancelled',
            'quantity' => 2,
        ]);
        $this->assertDatabaseHas('inventory_ledgers', [
            'trip_instance_id' => $f['newInstance']->id,
            'booking_id' => $f['booking']->id,
            'type' => 'confirmed',
            'quantity' => -2,
        ]);
        $this->assertDatabaseMissing('inventory_ledgers', ['type' => 'cancellation']);

        // Old trip regains the 2 seats, new trip consumes 2.
        $this->assertSame(10, $f['oldInstance']->fresh()->getRemainingSeatsAttribute());
        $this->assertSame(8, $f['newInstance']->fresh()->getRemainingSeatsAttribute());

        // Passenger categories/prices updated to the new trip's category.
        foreach ([$f['p1'], $f['p2']] as $pid) {
            $passenger = \App\Models\Passenger::find($pid);
            $this->assertEquals($f['newCat']->id, $passenger->trip_passenger_category_id);
            $this->assertEquals(15000, $passenger->getRawOriginal('price_at_booking'), 'price_at_booking should be $150.00 (15000 cents).');
        }

        // Booking moved to the new trip; totals recomputed in cents from the new category
        // prices (2 x $150 = $300 = 30000 cents), not the 100x-too-small value the original
        // buggy formula would have produced by writing the MoneyCast-cast (dollar) sum
        // straight into the raw cents column.
        $fresh = $f['booking']->fresh();
        $this->assertEquals($f['newInstance']->id, $fresh->trip_instance_id);
        $this->assertEquals(30000, DB::table('bookings')->where('id', $fresh->id)->value('grand_total'));
        $this->assertEquals(30000, DB::table('bookings')->where('id', $fresh->id)->value('balance_due'));
    }

    public function test_transfer_booking_blocked_when_destination_full_no_partial_writes(): void
    {
        $f = $this->makeTransferFixture('b', oldSeats: 10, newSeats: 1); // only 1 seat, 2 passengers need transfer

        $ledgerCountBefore = InventoryLedger::count();

        $this->expectException(InsufficientSeatsException::class);

        try {
            app(BookingService::class)->transferBooking(
                $f['booking'],
                $f['newInstance'],
                [$f['p1'] => $f['newCat']->id, $f['p2'] => $f['newCat']->id]
            );
        } finally {
            // No partial ledger writes from the aborted transfer (transaction rolled back).
            $this->assertSame($ledgerCountBefore, InventoryLedger::count());
            $this->assertDatabaseMissing('inventory_ledgers', ['type' => 'cancellation']);

            $fresh = $f['booking']->fresh();
            $this->assertEquals($f['oldInstance']->id, $fresh->trip_instance_id, 'Booking must remain on the original trip when the transfer is blocked.');
        }
    }

    // ------------------------------------------------------------------
    // Hotel/Rooming series, final item: destination trip lifecycle status
    // ------------------------------------------------------------------

    private function assertTransferRejectedForDestinationStatus(string $suffix, string $status): void
    {
        $f = $this->makeTransferFixture($suffix, newStatus: $status);
        $ledgerCountBefore = InventoryLedger::count();

        $this->expectException(\App\Exceptions\InvalidTransferDestinationException::class);

        try {
            app(BookingService::class)->transferBooking(
                $f['booking'],
                $f['newInstance'],
                [$f['p1'] => $f['newCat']->id, $f['p2'] => $f['newCat']->id]
            );
        } finally {
            // Zero mutation, zero partial ledger writes -- rejected before any inventory
            // consumption or booking mutation, inside the same lock/transaction.
            $this->assertSame($ledgerCountBefore, InventoryLedger::count());
            $fresh = $f['booking']->fresh();
            $this->assertEquals($f['oldInstance']->id, $fresh->trip_instance_id, 'Booking must remain on the original trip when the destination status is invalid.');
            $this->assertEquals($f['oldCat']->id, \App\Models\Passenger::find($f['p1'])->trip_passenger_category_id, 'Passenger category must be unchanged.');
        }
    }

    public function test_transfer_booking_rejected_for_cancelled_destination(): void
    {
        $this->assertTransferRejectedForDestinationStatus('status-cancelled', 'cancelled');
    }

    public function test_transfer_booking_rejected_for_completed_destination(): void
    {
        $this->assertTransferRejectedForDestinationStatus('status-completed', 'completed');
    }

    public function test_transfer_booking_rejected_for_inprogress_destination(): void
    {
        $this->assertTransferRejectedForDestinationStatus('status-inprogress', 'in_progress');
    }

    private function assertTransferSucceedsForDestinationStatus(string $suffix, string $status): void
    {
        // Re-confirms existing behavior is unchanged for the "normal lifecycle" statuses
        // already established in TripService::cancelTrip() -- same set, same meaning here.
        $f = $this->makeTransferFixture($suffix, newStatus: $status);

        app(BookingService::class)->transferBooking(
            $f['booking'],
            $f['newInstance'],
            [$f['p1'] => $f['newCat']->id, $f['p2'] => $f['newCat']->id]
        );

        $fresh = $f['booking']->fresh();
        $this->assertEquals($f['newInstance']->id, $fresh->trip_instance_id, "Transfer to a '{$status}' destination must still succeed.");
        $this->assertDatabaseHas('inventory_ledgers', [
            'trip_instance_id' => $f['newInstance']->id,
            'booking_id' => $f['booking']->id,
            'type' => 'confirmed',
            'quantity' => -2,
        ]);
    }

    public function test_transfer_booking_still_succeeds_for_draft_destination(): void
    {
        $this->assertTransferSucceedsForDestinationStatus('valid-status-draft', 'draft');
    }

    public function test_transfer_booking_still_succeeds_for_active_destination(): void
    {
        $this->assertTransferSucceedsForDestinationStatus('valid-status-active', 'active');
    }

    public function test_transfer_booking_still_succeeds_for_closed_destination(): void
    {
        $this->assertTransferSucceedsForDestinationStatus('valid-status-closed', 'closed');
    }

    public function test_transfer_booking_status_check_fails_before_capacity_check(): void
    {
        // Destination is BOTH cancelled AND full (0 remaining seats for a 2-passenger
        // transfer) -- must reject for the status reason, not the capacity reason, proving
        // the status check runs first ("fail fast, cleaner rejection reason").
        $f = $this->makeTransferFixture('status-before-capacity', oldSeats: 10, newSeats: 0, newStatus: 'cancelled');
        $ledgerCountBefore = InventoryLedger::count();

        try {
            app(BookingService::class)->transferBooking(
                $f['booking'],
                $f['newInstance'],
                [$f['p1'] => $f['newCat']->id, $f['p2'] => $f['newCat']->id]
            );
            $this->fail('Expected InvalidTransferDestinationException to be thrown.');
        } catch (InsufficientSeatsException $e) {
            $this->fail('Status check must fail BEFORE the capacity check -- got InsufficientSeatsException instead of InvalidTransferDestinationException.');
        } catch (\App\Exceptions\InvalidTransferDestinationException $e) {
            // Expected.
        }

        $this->assertSame($ledgerCountBefore, InventoryLedger::count());
        $this->assertEquals($f['oldInstance']->id, $f['booking']->fresh()->trip_instance_id);
    }

    public function test_transfer_booking_capacity_check_reads_live_state_not_a_stale_snapshot(): void
    {
        // Exactly enough seats for the 2-passenger transfer, if read at fixture-creation time.
        $f = $this->makeTransferFixture('c', oldSeats: 10, newSeats: 2);

        // Simulate a concurrent booking/transfer that consumes the destination trip's last
        // seat AFTER the admin's UI would have loaded remaining_seats but BEFORE this transfer
        // actually commits - this is exactly the race the two prior implementations were
        // vulnerable to (both read TripInstance::remaining_seats before opening any
        // transaction/lock). transferBooking() must recheck live state, not trust a
        // pre-fetched value.
        InventoryLedger::create([
            'trip_instance_id' => $f['newInstance']->id,
            'quantity' => -1,
            'type' => 'confirmed',
            'notes' => 'Concurrent booking consuming the last seat',
        ]);

        $this->expectException(InsufficientSeatsException::class);

        // Pass the SAME $newInstance model instance that was fetched by the fixture, before
        // the concurrent consumption above - proving the check is not merely re-reading an
        // in-memory property but re-querying the ledger fresh at call time.
        app(BookingService::class)->transferBooking(
            $f['booking'],
            $f['newInstance'],
            [$f['p1'] => $f['newCat']->id, $f['p2'] => $f['newCat']->id]
        );
    }

    public function test_transfer_booking_locks_booking_and_destination_trip_inside_the_transaction(): void
    {
        // Structural guarantee (the runtime lock itself isn't observable in a single-process
        // SQLite :memory: test - see the project's established precedent for this exact
        // limitation in test_view_booking_cancellation_action_uses_valid_enum_literal):
        // assert transferBooking() locks both rows for update, and that the capacity-checking
        // InventoryService::transferSeats() call happens INSIDE the DB::transaction closure,
        // not before it opens - the exact ordering bug both prior implementations had.
        $source = file_get_contents(app_path('Services/BookingService.php'));
        $methodStart = strpos($source, 'function transferBooking(');
        $this->assertNotFalse($methodStart, 'BookingService::transferBooking() must exist.');

        $nextMethodStart = strpos($source, "\n    public function ", $methodStart + 1);
        $methodBody = substr($source, $methodStart, ($nextMethodStart ?: strlen($source)) - $methodStart);

        $this->assertStringContainsString(
            "Booking::where('id', \$booking->id)->lockForUpdate()->firstOrFail()",
            $methodBody,
            'transferBooking() must lock the booking row for update.'
        );
        $this->assertStringContainsString(
            "TripInstance::where('id', \$newTrip->id)->lockForUpdate()->firstOrFail()",
            $methodBody,
            'transferBooking() must lock the destination TripInstance row for update.'
        );

        $transactionPos = strpos($methodBody, 'DB::transaction(function');
        $bookingLockPos = strpos($methodBody, "Booking::where('id', \$booking->id)->lockForUpdate()");
        $tripLockPos = strpos($methodBody, "TripInstance::where('id', \$newTrip->id)->lockForUpdate()");
        $transferSeatsPos = strpos($methodBody, '->transferSeats(');

        $this->assertNotFalse($transactionPos);
        $this->assertNotFalse($transferSeatsPos);
        $this->assertGreaterThan($transactionPos, $bookingLockPos, 'Booking lock must happen inside the transaction.');
        $this->assertGreaterThan($transactionPos, $tripLockPos, 'Destination trip lock must happen inside the transaction.');
        $this->assertGreaterThan($tripLockPos, $transferSeatsPos, 'The capacity-checking transferSeats() call must happen after the destination trip is locked.');
    }

    public function test_transfer_booking_rejects_already_cancelled_booking(): void
    {
        $f = $this->makeTransferFixture('d');
        app(BookingService::class)->cancelBooking($f['booking']);

        $ledgerCountBefore = InventoryLedger::count();

        $this->expectException(\RuntimeException::class);
        try {
            app(BookingService::class)->transferBooking(
                $f['booking'],
                $f['newInstance'],
                [$f['p1'] => $f['newCat']->id, $f['p2'] => $f['newCat']->id]
            );
        } finally {
            $this->assertSame($ledgerCountBefore, InventoryLedger::count(), 'No new ledger rows should be written when the transfer is rejected.');
        }
    }

    public function test_transfer_booking_is_idempotent_when_already_on_destination_trip(): void
    {
        $f = $this->makeTransferFixture('e');
        $map = [$f['p1'] => $f['newCat']->id, $f['p2'] => $f['newCat']->id];

        app(BookingService::class)->transferBooking($f['booking'], $f['newInstance'], $map);
        $countAfterFirst = InventoryLedger::count();

        // A retried/overlapping call against a booking already on the destination trip must
        // be a silent no-op, not a second transfer (which would double-consume/double-release).
        app(BookingService::class)->transferBooking($f['booking']->fresh(), $f['newInstance'], $map);

        $this->assertSame($countAfterFirst, InventoryLedger::count(), 'Repeating the transfer to the same destination must not write additional ledger rows.');
        $this->assertSame(8, $f['newInstance']->fresh()->getRemainingSeatsAttribute(), 'Destination seats must not be double-consumed.');
    }

    public function test_booking_resource_transfer_action_delegates_to_booking_service(): void
    {
        $f = $this->makeTransferFixture('f');

        // Same closure-extraction pattern already established in this suite for
        // BookingResource table actions (see test_partial_passenger_cancellation_...): no
        // working Livewire::test() mounting exists for Filament panel pages in this repo, so
        // the registered Action's real closure is extracted and invoked directly - this runs
        // the exact same production code as the admin UI. getAction() finds it even though
        // it's nested inside the row's ActionGroup, exactly like the existing
        // 'cancel_passengers' precedent in this same ActionGroup.
        $page = new \App\Filament\Resources\BookingResource\Pages\ListBookings();
        $table = \App\Filament\Resources\BookingResource::table(new \Filament\Tables\Table($page));
        $action = $table->getAction('transfer_booking');
        $this->assertNotNull($action, "The 'transfer_booking' action must be registered on BookingResource's table.");
        $closure = $action->getActionFunction();

        $closure([
            'new_trip_instance_id' => $f['newInstance']->id,
            "passenger_{$f['p1']}_category" => $f['newCat']->id,
            "passenger_{$f['p2']}_category" => $f['newCat']->id,
        ], $f['booking']);

        $this->assertDatabaseHas('inventory_ledgers', [
            'trip_instance_id' => $f['oldInstance']->id,
            'booking_id' => $f['booking']->id,
            'type' => 'cancelled',
            'quantity' => 2,
        ]);
        $this->assertDatabaseHas('inventory_ledgers', [
            'trip_instance_id' => $f['newInstance']->id,
            'booking_id' => $f['booking']->id,
            'type' => 'confirmed',
            'quantity' => -2,
        ]);
        $this->assertDatabaseMissing('inventory_ledgers', ['type' => 'cancellation']);

        $fresh = $f['booking']->fresh();
        $this->assertEquals($f['newInstance']->id, $fresh->trip_instance_id);
        $this->assertEquals(30000, DB::table('bookings')->where('id', $fresh->id)->value('grand_total'));
        $this->assertEquals(30000, DB::table('bookings')->where('id', $fresh->id)->value('balance_due'));
    }

    public function test_booking_resource_transfer_action_blocks_when_destination_full(): void
    {
        $f = $this->makeTransferFixture('g', oldSeats: 10, newSeats: 1);

        $page = new \App\Filament\Resources\BookingResource\Pages\ListBookings();
        $table = \App\Filament\Resources\BookingResource::table(new \Filament\Tables\Table($page));
        $action = $table->getAction('transfer_booking');
        $closure = $action->getActionFunction();

        $ledgerCountBefore = InventoryLedger::count();

        // The row action catches InsufficientSeatsException itself (friendly notification,
        // no crash) rather than letting it propagate - assert that UX contract by expecting
        // no exception and no ledger writes, exactly like the standalone service-level test.
        $closure([
            'new_trip_instance_id' => $f['newInstance']->id,
            "passenger_{$f['p1']}_category" => $f['newCat']->id,
            "passenger_{$f['p2']}_category" => $f['newCat']->id,
        ], $f['booking']);

        $this->assertSame($ledgerCountBefore, InventoryLedger::count());
        $fresh = $f['booking']->fresh();
        $this->assertEquals($f['oldInstance']->id, $fresh->trip_instance_id);
    }

    public function test_view_booking_transfer_action_delegates_to_booking_service_and_has_no_invalid_enum_literal(): void
    {
        // Mirrors the project's existing precedent (test_view_booking_cancellation_action_uses_valid_enum_literal):
        // ViewBooking's action closures cannot be invoked directly outside a mounted Livewire
        // component (they end in $this->refreshFormData(...), which needs a real form/page
        // context this test harness cannot provide - a pre-existing, unrelated Filament
        // version incompatibility). Verify the wiring statically instead.
        $source = file_get_contents(app_path('Filament/Resources/BookingResource/Pages/ViewBooking.php'));

        $this->assertStringContainsString(
            "BookingService::class)->transferBooking(",
            $source,
            'ViewBooking transfer_booking action must delegate to the centralized BookingService::transferBooking().'
        );

        $this->assertStringNotContainsString(
            "'cancellation'",
            $source,
            'ViewBooking.php must not contain the invalid ledger enum literal "cancellation" anywhere.'
        );
    }

    public function test_booking_resource_php_has_no_invalid_enum_literal(): void
    {
        $source = file_get_contents(app_path('Filament/Resources/BookingResource.php'));

        $this->assertStringContainsString(
            "BookingService::class)->transferBooking(",
            $source,
            'BookingResource transfer_booking action must delegate to the centralized BookingService::transferBooking().'
        );

        $this->assertStringNotContainsString(
            "'cancellation'",
            $source,
            'BookingResource.php must not contain the invalid ledger enum literal "cancellation" anywhere.'
        );
    }
}
