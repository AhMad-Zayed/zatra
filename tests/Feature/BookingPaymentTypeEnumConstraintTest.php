<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TripInstance;
use App\Models\TripTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * SQLite/MySQL Behavioral Parity Audit follow-up: bookings.payment_type was added via
 * Schema::table(...)->enum(...) (an ALTER, not part of the original Schema::create()), which
 * Laravel's SQLite grammar silently turns into a plain, unconstrained VARCHAR -- confirmed empty-
 * handed: inserting an arbitrary string directly succeeded with no error before this fix. MySQL
 * (native ENUM type, strict mode) already rejected it correctly the whole time -- this is exactly
 * the "invisible to the SQLite-run test suite, live-breaking on MySQL" class of gap the parity
 * audit was looking for, except here it happened to run in the SAFE direction (SQLite too
 * permissive, not MySQL crashing), so nothing was actually broken in production -- but any bug
 * that produced an unexpected payment_type string would have sailed through the entire test
 * suite undetected.
 *
 * Fixed via a migration that forces Laravel's SQLite grammar through its table-rebuild path
 * (->change()), which does properly emit the CHECK constraint this time -- confirmed via the raw
 * sqlite_master schema before writing this test.
 */
class BookingPaymentTypeEnumConstraintTest extends TestCase
{
    use RefreshDatabase;

    public function test_sqlite_rejects_an_invalid_payment_type_value(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 'payment-type-check']);
        $template = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'X', 'base_price' => 100]);
        $instance = TripInstance::create([
            'tenant_id' => $tenant->id, 'trip_template_id' => $template->id,
            'start_date' => now()->addDays(5), 'end_date' => now()->addDays(6),
            'available_seats' => 10, 'status' => 'active',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('bookings')->insert([
            'tenant_id' => $tenant->id, 'trip_instance_id' => $instance->id, 'pnr' => 'ENUMTEST',
            'currency' => 'USD', 'booking_status' => 'pending', 'payment_status' => 'unpaid',
            'payment_type' => 'not_a_real_payment_type', 'grand_total' => 0, 'balance_due' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_valid_payment_type_values_are_still_accepted(): void
    {
        $tenant = Tenant::create(['name' => 'T2', 'slug' => 'payment-type-check-2']);
        $template = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'X', 'base_price' => 100]);
        $instance = TripInstance::create([
            'tenant_id' => $tenant->id, 'trip_template_id' => $template->id,
            'start_date' => now()->addDays(5), 'end_date' => now()->addDays(6),
            'available_seats' => 10, 'status' => 'active',
        ]);

        foreach (['full', 'deposit'] as $validType) {
            $id = DB::table('bookings')->insertGetId([
                'tenant_id' => $tenant->id, 'trip_instance_id' => $instance->id, 'pnr' => "ENUMOK-{$validType}",
                'currency' => 'USD', 'booking_status' => 'pending', 'payment_status' => 'unpaid',
                'payment_type' => $validType, 'grand_total' => 0, 'balance_due' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->assertDatabaseHas('bookings', ['id' => $id, 'payment_type' => $validType]);
        }
    }
}
