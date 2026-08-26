<?php

namespace Tests\Feature;

use App\Enums\TripTypeEnum;
use App\Filament\Pages\PhoneBookingPage;
use App\Filament\Resources\BookingResource\Pages\CreateBooking;
use App\Livewire\CheckoutWizard;
use App\Livewire\CustomerBookingPortal;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Passenger;
use App\Models\RequirementPreset;
use App\Models\Tenant;
use App\Models\TripInstance;
use App\Models\TripPassengerCategory;
use App\Models\TripTemplate;
use App\Models\User;
use App\Services\CreateBookingService;
use App\Services\RequirementValidationService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Regression coverage for:
 *  A. TripTypeEnum + trip_type (classification only, no automatic business logic).
 *  B. RequirementValidationService::findMissingRequirements() (fixing the field-mapping the
 *     dead CustomerBookingPortal::validatePassengers() `in_array()` check was reaching for).
 *  C. Enforcement wired into all 3 booking-creation entry points — strict (CheckoutWizard,
 *     text/date only) vs. permissive (PhoneBookingPage/admin Create Booking — QuickBookingPage
 *     retired, its coverage was equivalent to admin Create Booking's below), and per-passenger
 *     requirements_complete tracking (all item types, everywhere) regardless of strict/permissive
 *     outcome.
 *  D. CustomerBookingPortal::validatePassengers() actually enforcing now.
 *  E. requirements_complete cleared automatically once a document is uploaded post-booking.
 */
class TripTypeAndRequirementEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private CreateBookingService $createBookingService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createBookingService = new CreateBookingService();
    }

    /**
     * @param array<string> $types Item types to include on the preset, e.g. ['text','date','image'].
     * @return array{tenant: Tenant, customer: Customer, preset: RequirementPreset, template: TripTemplate, instance: TripInstance, cat: TripPassengerCategory}
     */
    private function makeFixture(string $suffix, array $types = [], int $availableSeats = 10, float $price = 100.00): array
    {
        $tenant = Tenant::create(['name' => "Agency {$suffix}", 'slug' => "agency-tre-{$suffix}", 'domain' => "{$suffix}.zatara.com"]);
        $customer = Customer::create(['name' => 'Jane', 'phone' => "0595{$suffix}", 'tenant_id' => $tenant->id]);

        $preset = null;
        if (!empty($types)) {
            $labels = ['text' => 'رقم الوثيقة', 'date' => 'تاريخ الميلاد', 'image' => 'صورة الجواز'];
            $preset = RequirementPreset::create([
                'tenant_id' => $tenant->id,
                'title' => 'Standard',
                'items' => array_map(fn ($type) => ['name' => $labels[$type], 'type' => $type, 'is_required' => true], $types),
            ]);
        }

        $template = TripTemplate::create([
            'tenant_id' => $tenant->id,
            'title' => 'Tour',
            'base_price' => $price,
            'requirement_preset_id' => $preset?->id,
        ]);
        $instance = TripInstance::create([
            'tenant_id' => $tenant->id,
            'trip_template_id' => $template->id,
            'start_date' => now()->addDays(5),
            'end_date' => now()->addDays(10),
            'available_seats' => $availableSeats,
            'status' => 'active',
        ]);
        $cat = TripPassengerCategory::create([
            'tenant_id' => $tenant->id, 'trip_instance_id' => $instance->id,
            'name' => 'Adult', 'price' => $price, 'requires_seat' => true,
        ]);

        return compact('tenant', 'customer', 'preset', 'template', 'instance', 'cat');
    }

    private function makeAgencyAdmin(Tenant $tenant, string $phone): User
    {
        Role::firstOrCreate(['name' => 'agency_admin']);
        Permission::firstOrCreate(['name' => 'panel_access_placeholder']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::create(['name' => 'Admin', 'phone' => $phone]);
        $user->tenants()->attach($tenant);
        setPermissionsTeamId($tenant->id);
        $user->assignRole('agency_admin');

        return $user;
    }

    // ------------------------------------------------------------------
    // A. TripTypeEnum / trip_type
    // ------------------------------------------------------------------

    public function test_trip_type_enum_is_exhaustive_for_label_and_color(): void
    {
        foreach (TripTypeEnum::cases() as $case) {
            $this->assertIsString($case->getLabel(), "TripTypeEnum::{$case->name} must have a label.");
            $this->assertNotNull($case->getColor(), "TripTypeEnum::{$case->name} must have a color.");
        }
    }

    public function test_trip_type_is_nullable_and_does_not_default(): void
    {
        $f = $this->makeFixture('a01');
        $this->assertNull($f['template']->fresh()->trip_type);
    }

    public function test_trip_type_drives_no_automatic_validation_or_visibility(): void
    {
        // Guardrail: setting trip_type has no effect on RequirementPreset enforcement or
        // PackageOption availability — both are governed entirely independently.
        $f = $this->makeFixture('a02', ['text']);
        $f['template']->update(['trip_type' => TripTypeEnum::International->value]);

        $service = new RequirementValidationService();
        $missingBefore = $service->findMissingRequirements($f['preset'], [[]]);

        $f['template']->update(['trip_type' => TripTypeEnum::Domestic->value]);
        $missingAfter = $service->findMissingRequirements($f['preset']->fresh(), [[]]);

        $this->assertEquals($missingBefore, $missingAfter, 'Changing trip_type must not change requirement enforcement outcomes.');
    }

    public function test_phone_booking_page_trip_filters_actually_filter_by_trip_type(): void
    {
        $tenant = Tenant::create(['name' => 'Agency PF', 'slug' => 'agency-pf', 'domain' => 'pf.zatara.com']);
        $admin = $this->makeAgencyAdmin($tenant, '0791199001');

        $domesticTemplate = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'Domestic Trip', 'base_price' => 50, 'trip_type' => TripTypeEnum::Domestic->value]);
        $intlTemplate = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'International Trip', 'base_price' => 500, 'trip_type' => TripTypeEnum::International->value]);

        TripInstance::create(['tenant_id' => $tenant->id, 'trip_template_id' => $domesticTemplate->id, 'start_date' => now()->addDays(3), 'end_date' => now()->addDays(3), 'available_seats' => 10, 'status' => 'active']);
        TripInstance::create(['tenant_id' => $tenant->id, 'trip_template_id' => $intlTemplate->id, 'start_date' => now()->addDays(4), 'end_date' => now()->addDays(6), 'available_seats' => 10, 'status' => 'active']);

        $this->actingAs($admin);
        Filament::setTenant($tenant, true);

        $component = Livewire::test(PhoneBookingPage::class)->call('toggleTripFilter', 'internal');
        $titles = collect($component->instance()->getTripResults())->pluck('title');
        $this->assertContains('Domestic Trip', $titles);
        $this->assertNotContains('International Trip', $titles);
    }

    // ------------------------------------------------------------------
    // B. RequirementValidationService
    // ------------------------------------------------------------------

    public function test_find_missing_requirements_returns_empty_when_no_preset_attached(): void
    {
        $service = new RequirementValidationService();
        $this->assertSame([], $service->findMissingRequirements(null, [['document_number' => null]]));
    }

    public function test_find_missing_requirements_detects_each_type_when_absent(): void
    {
        $f = $this->makeFixture('b01', ['text', 'date', 'image']);
        $service = new RequirementValidationService();

        $missing = $service->findMissingRequirements($f['preset'], [[]]);

        $this->assertCount(3, $missing);
        $this->assertEqualsCanonicalizing(['text', 'date', 'image'], array_column($missing, 'type'));
    }

    public function test_find_missing_requirements_is_empty_when_all_satisfied(): void
    {
        $f = $this->makeFixture('b02', ['text', 'date', 'image']);
        $service = new RequirementValidationService();

        $missing = $service->findMissingRequirements($f['preset'], [[
            'document_number' => 'P123',
            'date_of_birth' => '1990-01-01',
            'has_identity_document' => true,
        ]]);

        $this->assertSame([], $missing);
    }

    public function test_blocking_misses_excludes_image_type(): void
    {
        $f = $this->makeFixture('b03', ['text', 'date', 'image']);
        $service = new RequirementValidationService();

        $missing = $service->findMissingRequirements($f['preset'], [[]]);
        $blocking = $service->blockingMisses($missing);

        $this->assertCount(2, $blocking);
        foreach ($blocking as $miss) {
            $this->assertContains($miss['type'], ['text', 'date']);
        }
    }

    public function test_is_passenger_complete_is_per_passenger(): void
    {
        $f = $this->makeFixture('b04', ['text']);
        $service = new RequirementValidationService();

        $missing = $service->findMissingRequirements($f['preset'], [
            ['document_number' => 'P1'],  // index 0: satisfied
            [],                           // index 1: missing
        ]);

        $this->assertTrue($service->isPassengerComplete($missing, 0));
        $this->assertFalse($service->isPassengerComplete($missing, 1));
    }

    // ------------------------------------------------------------------
    // C. Wired into the 4 entry points
    // ------------------------------------------------------------------

    public function test_create_booking_service_persists_requirements_complete_per_passenger(): void
    {
        $f = $this->makeFixture('c01', ['text']);

        $booking = $this->createBookingService->execute([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'customer_id' => $f['customer']->id,
            'passengersData' => [
                ['trip_passenger_category_id' => $f['cat']->id, 'first_name' => 'Has', 'last_name' => 'Doc', 'document_number' => 'P1'],
                ['trip_passenger_category_id' => $f['cat']->id, 'first_name' => 'No', 'last_name' => 'Doc'],
            ],
        ]);

        $passengers = $booking->passengers()->orderBy('id')->get();
        $this->assertTrue($passengers[0]->requirements_complete);
        $this->assertFalse($passengers[1]->requirements_complete);
    }

    public function test_create_booking_service_leaves_requirements_complete_true_when_no_preset_attached(): void
    {
        $f = $this->makeFixture('c02'); // no preset

        $booking = $this->createBookingService->execute([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'customer_id' => $f['customer']->id,
            'passengersData' => [
                ['trip_passenger_category_id' => $f['cat']->id, 'first_name' => 'P', 'last_name' => '1'],
            ],
        ]);

        $this->assertTrue($booking->passengers()->first()->requirements_complete);
    }

    public function test_checkout_wizard_blocks_booking_when_text_or_date_requirement_missing(): void
    {
        $f = $this->makeFixture('c03', ['text', 'date']);

        $component = Livewire::test(CheckoutWizard::class, ['tenant' => $f['tenant'], 'tripInstance' => $f['instance']])
            ->set('form.passengers.0.first_name', 'Jane')
            ->set('form.passengers.0.last_name', 'Doe')
            ->set('form.passengers.0.trip_passenger_category_id', $f['cat']->id)
            ->set('form.email', 'jane-c03@example.com')
            ->set('form.phone', '+966500000031')
            ->call('submitLeadCapture')
            ->call('submitPassengers')
            ->set('paymentType', 'full')
            ->set('paymentMethod', 'cash')
            ->call('submitBooking');

        $component->assertHasErrors();
        $this->assertSame(2, $component->get('currentStep'), 'Must be sent back to the passenger step.');
        $this->assertSame(0, Booking::count(), 'No booking must be created when strict requirements are missing.');
    }

    public function test_checkout_wizard_succeeds_when_text_and_date_requirements_satisfied(): void
    {
        $f = $this->makeFixture('c04', ['text', 'date']);

        Livewire::test(CheckoutWizard::class, ['tenant' => $f['tenant'], 'tripInstance' => $f['instance']])
            ->set('form.passengers.0.first_name', 'Jane')
            ->set('form.passengers.0.last_name', 'Doe')
            ->set('form.passengers.0.trip_passenger_category_id', $f['cat']->id)
            ->set('form.email', 'jane-c04@example.com')
            ->set('form.phone', '+966500000041')
            ->call('submitLeadCapture')
            ->set('form.passengers.0.document_number', 'P999888')
            ->set('form.passengers.0.date_of_birth', '1990-05-01')
            ->call('submitPassengers')
            ->set('paymentType', 'full')
            ->set('paymentMethod', 'cash')
            ->call('submitBooking')
            ->assertHasNoErrors();

        $this->assertSame(1, Booking::count());
        $this->assertTrue(Booking::first()->passengers()->first()->requirements_complete);
    }

    public function test_checkout_wizard_succeeds_but_leaves_requirements_complete_false_when_only_image_outstanding(): void
    {
        $f = $this->makeFixture('c05', ['text', 'date', 'image']);

        $component = Livewire::test(CheckoutWizard::class, ['tenant' => $f['tenant'], 'tripInstance' => $f['instance']])
            ->set('form.passengers.0.first_name', 'Jane')
            ->set('form.passengers.0.last_name', 'Doe')
            ->set('form.passengers.0.trip_passenger_category_id', $f['cat']->id)
            ->set('form.email', 'jane-c05@example.com')
            ->set('form.phone', '+966500000051')
            ->call('submitLeadCapture')
            ->set('form.passengers.0.document_number', 'P999888')
            ->set('form.passengers.0.date_of_birth', '1990-05-01')
            ->call('submitPassengers')
            ->set('paymentType', 'full')
            ->set('paymentMethod', 'cash')
            ->call('submitBooking');

        $component->assertHasNoErrors('The image-only gap must never block strict checkout.');
        $this->assertSame(1, Booking::count());
        $this->assertFalse(
            Booking::first()->passengers()->first()->requirements_complete,
            'A passenger that passed strict text/date validation must still be flagged incomplete while an image item is outstanding.'
        );
    }

    public function test_phone_booking_page_never_blocks_and_flags_incomplete_placeholder_passengers(): void
    {
        $f = $this->makeFixture('c08', ['text']);
        $admin = $this->makeAgencyAdmin($f['tenant'], '0791199008');
        $this->actingAs($admin);
        Filament::setTenant($f['tenant'], true);

        $component = Livewire::test(PhoneBookingPage::class)
            ->call('selectCustomer', $f['customer']->id, $f['customer']->name, $f['customer']->phone)
            ->call('selectTrip', $f['instance']->id)
            ->call('increment', 0)
            ->call('submit');

        $bookingId = $component->get('booking_id');
        $this->assertNotNull($bookingId, 'Missing requirements must never block a phone booking.');
        $this->assertFalse(Booking::find($bookingId)->passengers()->first()->requirements_complete);
        $component->assertNotified('تنبيه: بيانات ناقصة');
    }

    public function test_admin_create_booking_handle_record_creation_flags_incomplete_passengers_without_blocking(): void
    {
        // Same reflection-based approach already established in AdminBookingTest for this exact
        // page — full Filament panel/form testing isn't wired up for this resource.
        $f = $this->makeFixture('c09', ['text']);
        $admin = $this->makeAgencyAdmin($f['tenant'], '0791199009');
        $this->actingAs($admin);
        Filament::setTenant($f['tenant'], true);

        $page = new CreateBooking();
        $method = new \ReflectionMethod($page, 'handleRecordCreation');
        $method->setAccessible(true);

        $booking = $method->invoke($page, [
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'customer_id' => $f['customer']->id,
            'user_id' => $admin->id,
            'passengers' => [
                ['trip_passenger_category_id' => $f['cat']->id, 'first_name' => 'No', 'last_name' => 'Doc'],
            ],
        ]);

        $this->assertNotNull($booking);
        $this->assertFalse($booking->passengers()->first()->requirements_complete);
    }

    public function test_booking_resource_list_page_renders_with_requirements_status_column_and_filter(): void
    {
        $f = $this->makeFixture('c10', ['text']);
        $admin = $this->makeAgencyAdmin($f['tenant'], '0791199010');
        $this->actingAs($admin);

        $this->createBookingService->execute([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'customer_id' => $f['customer']->id,
            'passengersData' => [
                ['trip_passenger_category_id' => $f['cat']->id, 'first_name' => 'No', 'last_name' => 'Doc'],
            ],
        ]);

        $this->get("/admin/{$f['tenant']->id}/bookings")->assertSuccessful();

        Livewire::test(\App\Filament\Resources\BookingResource\Pages\ListBookings::class, ['tenant' => $f['tenant']])
            ->assertCanSeeTableRecords([Booking::first()])
            ->filterTable('requirements_status', false)
            ->assertCanSeeTableRecords([Booking::first()])
            ->filterTable('requirements_status', true)
            ->assertCanNotSeeTableRecords([Booking::first()]);
    }

    // ------------------------------------------------------------------
    // D. CustomerBookingPortal now actually enforcing
    // ------------------------------------------------------------------

    public function test_customer_booking_portal_now_rejects_incomplete_submission(): void
    {
        $f = $this->makeFixture('d01', ['text']);
        $booking = $this->createBookingService->execute([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'customer_id' => $f['customer']->id,
            'passengersData' => [
                ['trip_passenger_category_id' => $f['cat']->id, 'first_name' => 'P', 'last_name' => '1'],
            ],
        ]);

        $passengerId = $booking->passengers()->first()->id;

        Livewire::test(CustomerBookingPortal::class, ['uuid' => $booking->uuid])
            ->set('step', 2)
            ->set("passengersData.{$passengerId}.first_name", 'Jane')
            ->set("passengersData.{$passengerId}.last_name", 'Doe')
            // document_number deliberately left blank
            ->call('nextStep')
            ->assertHasErrors(["passengersData.{$passengerId}.document_number"])
            ->assertSet('step', 2, 'A rejected submission must not advance past the passenger step.');

        $this->assertFalse(Passenger::find($passengerId)->requirements_complete, 'A rejected submission must not have persisted anything.');
    }

    public function test_customer_booking_portal_accepts_and_persists_when_satisfied(): void
    {
        // saveAll()'s success path dispatches SendAtlahubWhatsAppJob; faking the queue avoids an
        // unrelated, pre-existing crash in AtlahubService when ATLAHUB_ACCOUNT_ID isn't set in
        // the test environment (it executes synchronously since QUEUE_CONNECTION=sync).
        \Illuminate\Support\Facades\Queue::fake();

        $f = $this->makeFixture('d02', ['text']);
        $booking = $this->createBookingService->execute([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'customer_id' => $f['customer']->id,
            'passengersData' => [
                ['trip_passenger_category_id' => $f['cat']->id, 'first_name' => 'P', 'last_name' => '1'],
            ],
        ]);
        $passengerId = $booking->passengers()->first()->id;
        $this->assertFalse($booking->passengers()->first()->requirements_complete, 'Sanity check: created without a document, must start incomplete.');

        Livewire::test(CustomerBookingPortal::class, ['uuid' => $booking->uuid])
            ->set('step', 2)
            ->set("passengersData.{$passengerId}.first_name", 'Jane')
            ->set("passengersData.{$passengerId}.last_name", 'Doe')
            ->set("passengersData.{$passengerId}.document_number", 'P555444')
            ->call('nextStep')
            ->assertHasNoErrors()
            ->call('nextStep'); // step 3 -> saveAll()

        $this->assertTrue(Passenger::find($passengerId)->requirements_complete);
    }

    // ------------------------------------------------------------------
    // E. requirements_complete cleared automatically on document upload
    // ------------------------------------------------------------------

    public function test_uploading_identity_document_clears_requirements_complete(): void
    {
        // See note in test_customer_booking_portal_accepts_and_persists_when_satisfied().
        \Illuminate\Support\Facades\Queue::fake();

        $f = $this->makeFixture('e01', ['image']);
        $booking = $this->createBookingService->execute([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'customer_id' => $f['customer']->id,
            'passengersData' => [
                ['trip_passenger_category_id' => $f['cat']->id, 'first_name' => 'P', 'last_name' => '1'],
            ],
        ]);
        $passenger = $booking->passengers()->first();
        $this->assertFalse($passenger->requirements_complete);

        \Illuminate\Http\UploadedFile::fake()->image('passport.jpg')->size(100);

        Livewire::test(CustomerBookingPortal::class, ['uuid' => $booking->uuid])
            ->set('step', 2)
            ->set("passengersData.{$passenger->id}.first_name", 'Jane')
            ->set("passengersData.{$passenger->id}.last_name", 'Doe')
            ->set("passengersData.{$passenger->id}.passport_file", \Illuminate\Http\UploadedFile::fake()->image('passport.jpg'))
            ->call('nextStep')
            ->assertHasNoErrors()
            ->call('nextStep');

        $this->assertTrue(Passenger::find($passenger->id)->requirements_complete);
        $this->assertTrue(Passenger::find($passenger->id)->hasMedia('identity_documents'));
    }
}
