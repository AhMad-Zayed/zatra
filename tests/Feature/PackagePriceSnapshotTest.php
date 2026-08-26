<?php

namespace Tests\Feature;

use App\Enums\PaymentType;
use App\Models\Customer;
use App\Models\PackageOption;
use App\Models\Tenant;
use App\Models\TripInstance;
use App\Models\TripPassengerCategory;
use App\Models\TripTemplate;
use App\Services\BookingService;
use App\Services\CreateBookingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Price Integrity Audit, Finding A (EMERGENCY HOTFIX): BookingService::recalculateTotals() read
 * $booking->packageOption->price_adjustment LIVE instead of a stored snapshot, corrupting the
 * stored grand_total column on nearly every subsequent booking mutation (payment, passenger
 * add/cancel, reopen) once a PackageOption's price changed after booking. Confirmed live in
 * Phase 0: a real booking's grand_total went from 150 to 600 after the package's live price
 * changed and a single new payment triggered recalculateTotals(). This test file reuses that
 * exact reproduction scenario.
 */
class PackagePriceSnapshotTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{tenant: Tenant, template: TripTemplate, instance: TripInstance, cat: TripPassengerCategory, package: PackageOption, customer: Customer}
     */
    private function makeFixture(string $suffix, int $packageAdjustment = 50): array
    {
        $tenant = Tenant::create(['name' => "Agency {$suffix}", 'slug' => "agency-pps-{$suffix}"]);
        $template = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'Original Trip Title', 'base_price' => 100]);
        $instance = TripInstance::create([
            'tenant_id' => $tenant->id, 'trip_template_id' => $template->id,
            'start_date' => now()->addDays(10), 'end_date' => now()->addDays(15),
            'available_seats' => 20, 'status' => 'active',
        ]);
        $cat = TripPassengerCategory::create([
            'tenant_id' => $tenant->id, 'trip_instance_id' => $instance->id,
            'name' => 'Adult', 'price' => 100, 'requires_seat' => true,
        ]);
        $package = PackageOption::create([
            'tenant_id' => $tenant->id, 'trip_instance_id' => $instance->id, 'name' => 'Standard Package',
            'room_type' => 'double', 'meal_plan' => 'full_board',
            'price_adjustment' => $packageAdjustment, 'available_seats' => 10, 'is_active' => true,
        ]);
        $customer = Customer::create(['tenant_id' => $tenant->id, 'name' => 'Jane', 'phone' => "0599{$suffix}"]);

        return compact('tenant', 'template', 'instance', 'cat', 'package', 'customer');
    }

    public function test_package_price_at_booking_is_captured_at_creation(): void
    {
        $f = $this->makeFixture('001');

        $booking = app(CreateBookingService::class)->execute([
            'tenant_id' => $f['tenant']->id, 'trip_instance_id' => $f['instance']->id, 'customer_id' => $f['customer']->id,
            'package_option_id' => $f['package']->id,
            'passengersData' => [['trip_passenger_category_id' => $f['cat']->id, 'first_name' => 'Jane', 'last_name' => 'Doe']],
        ]);

        $this->assertSame(50.0, (float) $booking->fresh()->package_price_at_booking);
    }

    public function test_grand_total_does_not_corrupt_after_a_live_package_price_change_and_a_new_payment(): void
    {
        // Exact Phase 0 reproduction: 150 -> (attempted corruption to) 600.
        $f = $this->makeFixture('002', packageAdjustment: 50);

        $booking = app(CreateBookingService::class)->execute([
            'tenant_id' => $f['tenant']->id, 'trip_instance_id' => $f['instance']->id, 'customer_id' => $f['customer']->id,
            'package_option_id' => $f['package']->id,
            'passengersData' => [['trip_passenger_category_id' => $f['cat']->id, 'first_name' => 'Jane', 'last_name' => 'Doe']],
        ]);
        $this->assertEquals(150, $booking->fresh()->grand_total, 'Sanity check: 100 (category) + 50 (package) at booking time.');

        // Live price change on the PackageOption, well after booking.
        $f['package']->update(['price_adjustment' => 500]);

        // Same trigger a real customer payment fires.
        app(BookingService::class)->recordPayment($booking, 10, 'cash', null, PaymentType::DEPOSIT);

        $this->assertEquals(150, $booking->fresh()->grand_total, 'grand_total must stay at the ORIGINAL 150, not drift to 600 (100 + the new live 500).');
    }

    public function test_package_charge_still_scales_with_passenger_count_using_the_frozen_per_unit_price(): void
    {
        // The snapshot is per-passenger (matching PackageOption.price_adjustment's own
        // semantic), not pre-multiplied -- adding a passenger must still scale the package
        // charge using the FROZEN per-unit price, not the live one.
        $f = $this->makeFixture('003', packageAdjustment: 50);

        $booking = app(CreateBookingService::class)->execute([
            'tenant_id' => $f['tenant']->id, 'trip_instance_id' => $f['instance']->id, 'customer_id' => $f['customer']->id,
            'package_option_id' => $f['package']->id,
            'passengersData' => [['trip_passenger_category_id' => $f['cat']->id, 'first_name' => 'P1', 'last_name' => 'X']],
        ]);

        $f['package']->update(['price_adjustment' => 500]); // live change, must NOT be used

        app(BookingService::class)->addPassengers($booking, [
            ['trip_passenger_category_id' => $f['cat']->id, 'first_name' => 'P2', 'last_name' => 'Y'],
        ]);

        // 2 passengers x $100 category + 2 x $50 FROZEN package adjustment = 300, not 2x100 + 2x500 = 1200.
        $this->assertEquals(300, $booking->fresh()->grand_total);
    }

    public function test_booking_without_a_package_option_is_unaffected(): void
    {
        $f = $this->makeFixture('004');

        $booking = app(CreateBookingService::class)->execute([
            'tenant_id' => $f['tenant']->id, 'trip_instance_id' => $f['instance']->id, 'customer_id' => $f['customer']->id,
            'passengersData' => [['trip_passenger_category_id' => $f['cat']->id, 'first_name' => 'Jane', 'last_name' => 'Doe']],
        ]);

        $this->assertNull($booking->fresh()->getRawOriginal('package_price_at_booking'));
        $this->assertEquals(100, $booking->fresh()->grand_total);
    }

    public function test_package_price_at_booking_is_immutable_once_set(): void
    {
        $f = $this->makeFixture('005');
        $booking = app(CreateBookingService::class)->execute([
            'tenant_id' => $f['tenant']->id, 'trip_instance_id' => $f['instance']->id, 'customer_id' => $f['customer']->id,
            'package_option_id' => $f['package']->id,
            'passengersData' => [['trip_passenger_category_id' => $f['cat']->id, 'first_name' => 'Jane', 'last_name' => 'Doe']],
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot modify immutable snapshot field: package_price_at_booking');

        $booking->update(['package_price_at_booking' => 999]);
    }

    // ------------------------------------------------------------------
    // Backfill migration for existing (pre-fix) bookings
    // ------------------------------------------------------------------

    public function test_backfill_arithmetic_back_derives_the_correct_historical_value(): void
    {
        $f = $this->makeFixture('007', packageAdjustment: 75);

        // A pre-fix historical booking: 1 passenger at $100, package charge baked into
        // grand_total at $75 (package_price_at_booking still null, as it would be for any row
        // that existed before this migration's column was added).
        $bookingId = DB::table('bookings')->insertGetId([
            'tenant_id' => $f['tenant']->id, 'trip_instance_id' => $f['instance']->id, 'customer_id' => $f['customer']->id,
            'package_option_id' => $f['package']->id, 'pnr' => 'BACKFILL-2', 'currency' => 'USD',
            'booking_status' => 'confirmed', 'payment_status' => 'unpaid',
            'grand_total' => 17500, 'balance_due' => 17500, 'discount_amount' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('passengers')->insert([
            'tenant_id' => $f['tenant']->id, 'booking_id' => $bookingId, 'trip_passenger_category_id' => $f['cat']->id,
            'price_at_booking' => 10000, 'data_complete' => true, 'requirements_complete' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Re-run the backfill's core logic directly (the migration's up() would try to
        // re-add the already-existing column) -- extracted here to prove the arithmetic.
        $passengerSum = (int) DB::table('passengers')->where('booking_id', $bookingId)->whereNull('deleted_at')->sum('price_at_booking');
        $grandTotal = (int) DB::table('bookings')->where('id', $bookingId)->value('grand_total');
        $discountCents = (int) DB::table('bookings')->where('id', $bookingId)->value('discount_amount');
        $passengerCount = (int) DB::table('passengers')->where('booking_id', $bookingId)->whereNull('deleted_at')->count();
        $derived = (int) round((($grandTotal - $passengerSum - 0 - 0 + $discountCents)) / $passengerCount);

        $this->assertSame(7500, $derived, 'Back-derived package_price_at_booking must equal the real $75.00 package adjustment (7500 cents).');
    }

    public function test_migration_backfills_a_real_pre_fix_booking_end_to_end(): void
    {
        // End-to-end proof using the migration file's OWN up() method, on a fresh schema where
        // the column does not exist yet -- exercises the exact code that will run in production.
        $f = $this->makeFixture('008', packageAdjustment: 75);

        // Manually drop the column this test's own RefreshDatabase run already added, to
        // simulate a pre-fix schema state, then insert a historical row before re-running up().
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('package_price_at_booking');
        });

        $bookingId = DB::table('bookings')->insertGetId([
            'tenant_id' => $f['tenant']->id, 'trip_instance_id' => $f['instance']->id, 'customer_id' => $f['customer']->id,
            'package_option_id' => $f['package']->id, 'pnr' => 'BACKFILL-3', 'currency' => 'USD',
            'booking_status' => 'confirmed', 'payment_status' => 'unpaid',
            'grand_total' => 17500, 'balance_due' => 17500, 'discount_amount' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('passengers')->insert([
            'tenant_id' => $f['tenant']->id, 'booking_id' => $bookingId, 'trip_passenger_category_id' => $f['cat']->id,
            'price_at_booking' => 10000, 'data_complete' => true, 'requirements_complete' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_09_04_000001_add_package_price_at_booking_to_bookings_table.php');
        $migration->up();

        $this->assertSame(7500, (int) DB::table('bookings')->where('id', $bookingId)->value('package_price_at_booking'));
    }
}
