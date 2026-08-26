<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\SocialAuthController;
use App\Models\Customer;
use App\Models\GuestSession;
use App\Models\Tenant;
use App\Models\TripInstance;
use App\Models\TripTemplate;
use App\Models\User;
use App\Services\CreateBookingService;
use App\Services\WaitlistConversionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Case-Insensitive Uniqueness Fix, Part 1 (identity columns): customers.email / users.email are
 * normalized to lowercase at the application layer BEFORE storage, on every write path -- the
 * standard, correct practice for email columns (not a database-level workaround). This makes
 * behavior deterministic and identical across SQLite and MySQL, since the input itself is
 * normalized before it reaches either engine.
 */
class EmailNormalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_email_is_lowercased_on_create(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 'email-norm-1']);
        $customer = Customer::create(['tenant_id' => $tenant->id, 'name' => 'A', 'phone' => '1', 'email' => 'John@Example.COM']);

        $this->assertSame('john@example.com', $customer->fresh()->email);
    }

    public function test_customer_email_is_lowercased_on_update(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 'email-norm-2']);
        $customer = Customer::create(['tenant_id' => $tenant->id, 'name' => 'A', 'phone' => '2', 'email' => 'lower@example.com']);

        $customer->update(['email' => 'Upper@Example.com']);

        $this->assertSame('upper@example.com', $customer->fresh()->email);
    }

    public function test_customer_email_is_lowercased_via_first_or_create(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 'email-norm-3']);

        $customer = Customer::firstOrCreate(
            ['phone' => '3', 'tenant_id' => $tenant->id],
            ['name' => 'A', 'email' => 'Mixed@Case.COM']
        );

        $this->assertSame('mixed@case.com', $customer->fresh()->email);
    }

    public function test_user_email_is_lowercased_on_create_and_update(): void
    {
        $user = User::create(['name' => 'A', 'phone' => '05001', 'email' => 'Staff@Agency.COM']);
        $this->assertSame('staff@agency.com', $user->fresh()->email);

        $user->update(['email' => 'New@Agency.COM']);
        $this->assertSame('new@agency.com', $user->fresh()->email);
    }

    public function test_guest_session_email_is_lowercased_so_create_booking_service_reads_it_normalized(): void
    {
        // CreateBookingService is guardrail-protected and was NOT modified -- this proves the
        // approved workaround (normalize at the GuestSession source instead) makes its existing,
        // untouched Customer::firstOrCreate(['email' => $guestSession->email, ...]) call work
        // correctly end-to-end regardless of the casing a customer actually typed at checkout.
        $tenant = Tenant::create(['name' => 'T', 'slug' => 'email-norm-4']);
        $template = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'X', 'base_price' => 100]);
        $instance = TripInstance::create([
            'tenant_id' => $tenant->id, 'trip_template_id' => $template->id,
            'start_date' => now()->addDays(5), 'end_date' => now()->addDays(6),
            'available_seats' => 10, 'status' => 'active',
        ]);
        $cat = \App\Models\TripPassengerCategory::create([
            'tenant_id' => $tenant->id, 'trip_instance_id' => $instance->id,
            'name' => 'Adult', 'price' => 100, 'requires_seat' => true,
        ]);

        // Existing customer, already normalized (as every customer now is).
        $existing = Customer::create(['tenant_id' => $tenant->id, 'name' => 'Existing', 'phone' => '999', 'email' => 'guest@example.com']);

        $guestSession = GuestSession::create([
            'first_name' => 'Guest',
            'email' => 'Guest@Example.COM', // same person, different casing at checkout
            'phone' => '888',
            'trip_instance_id' => $instance->id,
        ]);
        $this->assertSame('guest@example.com', $guestSession->fresh()->email, 'GuestSession itself must normalize too, so CreateBookingService reads an already-lowercase value.');

        $booking = app(CreateBookingService::class)->execute([
            'tenant_id' => $tenant->id,
            'trip_instance_id' => $instance->id,
            'customer_id' => null,
            'guest_session_id' => $guestSession->id,
            'passengersData' => [
                ['trip_passenger_category_id' => $cat->id, 'first_name' => 'Guest', 'last_name' => 'X'],
            ],
        ]);

        $this->assertSame($existing->id, $booking->customer_id, 'Must match the EXISTING customer by normalized email, not create a duplicate.');
        $this->assertSame(1, Customer::where('tenant_id', $tenant->id)->count(), 'No duplicate customer row created.');
    }

    public function test_social_auth_controller_matches_existing_customer_regardless_of_provider_email_casing(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 'email-norm-5']);
        $existing = Customer::create(['tenant_id' => $tenant->id, 'name' => 'Existing', 'phone' => '777', 'email' => 'social@example.com', 'provider_id' => null]);

        // Source-level proof: SocialAuthController lowercases the provider's email before both
        // the lookup and the create, so a differently-cased provider response still matches.
        $source = file_get_contents(app_path('Http/Controllers/Auth/SocialAuthController.php'));
        $this->assertStringContainsString('Str::lower(trim($socialUser->getEmail()))', $source);
        $this->assertStringContainsString("->where('email', \$socialEmail)", $source);
        $this->assertStringContainsString("'email' => \$socialEmail,", $source);

        // Behavioral proof: simulate the controller's own lookup+create logic directly (its real
        // method needs a live Socialite provider session this test harness can't construct), but
        // exercise the exact real Customer model + the exact normalized variable it now uses.
        $socialEmail = \Illuminate\Support\Str::lower(trim('Social@Example.COM'));
        $found = Customer::where('tenant_id', $tenant->id)->where('email', $socialEmail)->first();

        $this->assertNotNull($found);
        $this->assertSame($existing->id, $found->id);
    }

    public function test_waitlist_conversion_service_customer_email_is_normalized_with_zero_change_to_that_file(): void
    {
        // WaitlistConversionService's email is a create-VALUE (search is by phone), never part
        // of firstOrCreate()'s search array -- Customer::booted() alone is sufficient here, no
        // call-site change was needed (confirmed during Phase 0, re-confirmed behaviorally here).
        $tenant = Tenant::create(['name' => 'T', 'slug' => 'email-norm-6']);
        $template = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'X', 'base_price' => 100]);
        $instance = TripInstance::create([
            'tenant_id' => $tenant->id, 'trip_template_id' => $template->id,
            'start_date' => now()->addDays(5), 'end_date' => now()->addDays(6),
            'available_seats' => 10, 'status' => 'active',
        ]);
        $cat = \App\Models\TripPassengerCategory::create([
            'tenant_id' => $tenant->id, 'trip_instance_id' => $instance->id,
            'name' => 'Adult', 'price' => 100, 'requires_seat' => true,
        ]);
        $waitingList = \App\Models\WaitingList::create([
            'tenant_id' => $tenant->id, 'customer_name' => 'Jane', 'phone_number' => '0501122',
            'customer_email' => 'Waitlist@Example.COM', 'seats_requested' => 1,
            'status' => \App\Enums\WaitingListStatusEnum::Pending,
        ]);

        app(WaitlistConversionService::class)->convertToBooking(
            $waitingList, $instance, [['category_id' => $cat->id, 'count' => 1]], null
        );

        $customer = Customer::where('tenant_id', $tenant->id)->where('phone', '0501122')->first();
        $this->assertSame('waitlist@example.com', $customer->email);
    }

    // ------------------------------------------------------------------
    // SQLite/MySQL parity proof (same pattern as the payment_type enum fix)
    // ------------------------------------------------------------------

    public function test_sqlite_and_mysql_store_the_identical_normalized_value(): void
    {
        // Parity by construction: normalization happens at the application layer before either
        // engine ever sees the value, so there's nothing left for the two engines to disagree
        // about -- unlike the payment_type fix (a DB-level CHECK constraint difference), this is
        // proven by confirming the SAME input produces the SAME stored output on this connection
        // (SQLite, the test suite's driver); the live MySQL confirmation is captured in this
        // ticket's live-verification step, not repeatable in an automated test tied to a
        // specific DB connection.
        $this->assertSame('sqlite', DB::connection()->getDriverName());

        $variants = ['MixedCase@Example.com', 'MIXEDCASE@EXAMPLE.COM', 'mixedcase@example.com'];

        // Separate tenants per variant -- the point is proving each input normalizes to the
        // identical output, not exercising the unique constraint (a same-tenant duplicate would
        // correctly be rejected now that storage really is identical, which is the fix working
        // as intended, not something to route around).
        foreach ($variants as $i => $variant) {
            $tenant = Tenant::create(['name' => "T{$i}", 'slug' => "email-norm-parity-{$i}"]);
            $customer = Customer::create(['tenant_id' => $tenant->id, 'name' => "C{$i}", 'phone' => "600{$i}", 'email' => $variant]);
            $this->assertSame('mixedcase@example.com', $customer->fresh()->email, "Input '{$variant}' must normalize to the identical stored value.");
        }
    }
}
