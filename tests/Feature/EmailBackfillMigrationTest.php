<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Case-Insensitive Uniqueness Fix: the lowercase-backfill migration
 * (2026_09_03_000001_backfill_lowercase_customer_and_user_emails.php) is defensive by design --
 * the dev database being clean does not guarantee production is. These tests seed rows directly
 * via DB::table()->insert() (bypassing Customer::booted()'s normalization, to simulate real
 * pre-fix legacy data) and re-invoke the migration's up() method against that seeded state, since
 * RefreshDatabase already ran it once against an empty table before the test body executes.
 */
class EmailBackfillMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function runMigration(): void
    {
        $migration = require database_path('migrations/2026_09_03_000001_backfill_lowercase_customer_and_user_emails.php');
        $migration->up();
    }

    public function test_collision_scenario_is_not_silently_merged_and_is_logged(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 'backfill-collision']);

        // Simulates real pre-fix legacy data: two customers that will become identical once
        // lowercased. Inserted via the query builder directly, bypassing Customer::booted().
        $id1 = DB::table('customers')->insertGetId([
            'tenant_id' => $tenant->id, 'name' => 'Person A', 'phone' => '7001',
            'email' => 'Dup@Example.com', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $id2 = DB::table('customers')->insertGetId([
            'tenant_id' => $tenant->id, 'name' => 'Person B', 'phone' => '7002',
            'email' => 'dup@example.com', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // A clean, non-colliding row that must still get normalized.
        $id3 = DB::table('customers')->insertGetId([
            'tenant_id' => $tenant->id, 'name' => 'Person C', 'phone' => '7003',
            'email' => 'Clean@Example.com', 'created_at' => now(), 'updated_at' => now(),
        ]);

        Log::spy();

        $this->runMigration();

        // Neither colliding row was touched -- original casing preserved, nothing merged or
        // deleted, no "winner" picked.
        $this->assertSame('Dup@Example.com', DB::table('customers')->where('id', $id1)->value('email'));
        $this->assertSame('dup@example.com', DB::table('customers')->where('id', $id2)->value('email'));
        $this->assertSame(2, DB::table('customers')->where('id', '!=', $id3)->count(), 'Both colliding rows must still exist -- neither deleted nor merged.');

        // The clean, non-colliding row WAS normalized -- one collision must not block the rest.
        $this->assertSame('clean@example.com', DB::table('customers')->where('id', $id3)->value('email'));

        // The collision was logged, not silently ignored.
        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context) use ($tenant, $id1, $id2) {
                if (!str_contains($message, 'colliding email group')) {
                    return false;
                }
                if ($context['table'] !== 'customers' || $context['tenant_id'] !== $tenant->id) {
                    return false;
                }
                $loggedIds = collect($context['colliding_rows'])->pluck('id')->all();

                return in_array($id1, $loggedIds, true) && in_array($id2, $loggedIds, true);
            })
            ->once();
    }

    public function test_no_collision_normalizes_everything_and_logs_nothing(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 'backfill-clean']);

        $id1 = DB::table('customers')->insertGetId([
            'tenant_id' => $tenant->id, 'name' => 'A', 'phone' => '8001',
            'email' => 'One@Example.com', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $id2 = DB::table('customers')->insertGetId([
            'tenant_id' => $tenant->id, 'name' => 'B', 'phone' => '8002',
            'email' => 'Two@Example.com', 'created_at' => now(), 'updated_at' => now(),
        ]);

        Log::spy();

        $this->runMigration();

        $this->assertSame('one@example.com', DB::table('customers')->where('id', $id1)->value('email'));
        $this->assertSame('two@example.com', DB::table('customers')->where('id', $id2)->value('email'));

        Log::shouldNotHaveReceived('warning');
    }

    public function test_collision_scoped_per_tenant_does_not_block_a_different_tenants_clean_data(): void
    {
        $tenantA = Tenant::create(['name' => 'TA', 'slug' => 'backfill-ta']);
        $tenantB = Tenant::create(['name' => 'TB', 'slug' => 'backfill-tb']);

        // Same-looking emails, but different tenants -- customers.email is tenant-scoped, so this
        // must NOT be treated as a collision at all.
        $idA = DB::table('customers')->insertGetId([
            'tenant_id' => $tenantA->id, 'name' => 'A', 'phone' => '9001',
            'email' => 'Shared@Example.com', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $idB = DB::table('customers')->insertGetId([
            'tenant_id' => $tenantB->id, 'name' => 'B', 'phone' => '9002',
            'email' => 'shared@example.com', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->runMigration();

        $this->assertSame('shared@example.com', DB::table('customers')->where('id', $idA)->value('email'));
        $this->assertSame('shared@example.com', DB::table('customers')->where('id', $idB)->value('email'));
    }

    public function test_users_table_backfilled_without_tenant_scoping(): void
    {
        $id1 = DB::table('users')->insertGetId([
            'name' => 'Staff', 'phone' => '0501000', 'email' => 'Staff@Agency.com',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->runMigration();

        $this->assertSame('staff@agency.com', DB::table('users')->where('id', $id1)->value('email'));
    }
}
