<?php

namespace Tests\Feature;

use App\Filament\Resources\TripTemplateResource\Pages\EditTripTemplate;
use App\Livewire\CheckoutWizard;
use App\Livewire\StorefrontCatalog;
use App\Livewire\TripDetails;
use App\Models\Booking;
use App\Models\Tenant;
use App\Models\TripInstance;
use App\Models\TripPassengerCategory;
use App\Models\TripTemplate;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Regression coverage for the storefront redesign's Phase F -- a small, non-urgent follow-up
 * ticket scoped from the mockup-vs-live comparison report: (1) catalog price/category filter
 * sidebar, (2) per-category price table on trip-details, (3) richer payment-step order summary,
 * (4) a "my bookings" link on the confirmation page, and (5) making the previously-dead
 * itinerary-timeline UI actually functional (real column + admin field).
 */
class StorefrontPhaseFTest extends TestCase
{
    use RefreshDatabase;

    private function makeFixture(string $suffix): array
    {
        $tenant = Tenant::create(['name' => 'Agency PhaseF'.$suffix, 'slug' => 'agency-phasef'.$suffix]);
        $template = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'Phase F Trip', 'base_price' => 0, 'is_active' => true]);
        $instance = TripInstance::create([
            'tenant_id' => $tenant->id, 'trip_template_id' => $template->id,
            'start_date' => now()->addDays(20), 'end_date' => now()->addDays(25),
            'available_seats' => 20, 'status' => 'active',
        ]);

        return compact('tenant', 'template', 'instance');
    }

    // ------------------------------------------------------------------
    // Item 1: catalog filter sidebar
    // ------------------------------------------------------------------

    public function test_category_filter_excludes_templates_of_a_different_trip_type(): void
    {
        $tenant = Tenant::create(['name' => 'Agency F1', 'slug' => 'agency-f1']);
        $domestic = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'Domestic Trip', 'base_price' => 100, 'is_active' => true, 'trip_type' => 'domestic']);
        $international = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'International Trip', 'base_price' => 200, 'is_active' => true, 'trip_type' => 'international']);
        foreach ([$domestic, $international] as $t) {
            TripInstance::create([
                'tenant_id' => $tenant->id, 'trip_template_id' => $t->id,
                'start_date' => now()->addDays(20), 'end_date' => now()->addDays(25),
                'available_seats' => 20, 'status' => 'active',
            ]);
        }

        $html = Livewire::test(StorefrontCatalog::class, ['tenant' => $tenant])
            ->set('categories', ['domestic'])
            ->html();

        $this->assertStringContainsString('Domestic Trip', $html);
        $this->assertStringNotContainsString('International Trip', $html);
    }

    public function test_price_range_filter_excludes_templates_outside_the_range(): void
    {
        $tenant = Tenant::create(['name' => 'Agency F2', 'slug' => 'agency-f2']);
        $cheap = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'Cheap Trip', 'base_price' => 100, 'is_active' => true]);
        $expensive = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'Expensive Trip', 'base_price' => 9000, 'is_active' => true]);
        foreach ([$cheap, $expensive] as $t) {
            TripInstance::create([
                'tenant_id' => $tenant->id, 'trip_template_id' => $t->id,
                'start_date' => now()->addDays(20), 'end_date' => now()->addDays(25),
                'available_seats' => 20, 'status' => 'active',
            ]);
        }

        $html = Livewire::test(StorefrontCatalog::class, ['tenant' => $tenant])
            ->set('priceMax', 500)
            ->html();

        $this->assertStringContainsString('Cheap Trip', $html);
        $this->assertStringNotContainsString('Expensive Trip', $html);
    }

    public function test_reset_filters_clears_all_filter_state(): void
    {
        $tenant = Tenant::create(['name' => 'Agency F3', 'slug' => 'agency-f3']);

        Livewire::test(StorefrontCatalog::class, ['tenant' => $tenant])
            ->set('categories', ['domestic'])
            ->set('priceMin', 100)
            ->set('priceMax', 500)
            ->call('resetFilters')
            ->assertSet('categories', [])
            ->assertSet('priceMin', null)
            ->assertSet('priceMax', null);
    }

    // ------------------------------------------------------------------
    // Item 2: per-category price table on trip-details
    // ------------------------------------------------------------------

    public function test_trip_details_shows_the_per_category_price_table(): void
    {
        $f = $this->makeFixture('4');
        TripPassengerCategory::create([
            'tenant_id' => $f['tenant']->id, 'trip_instance_id' => $f['instance']->id,
            'name' => 'Adult', 'price' => 5000, 'requires_seat' => true,
        ]);
        TripPassengerCategory::create([
            'tenant_id' => $f['tenant']->id, 'trip_instance_id' => $f['instance']->id,
            'name' => 'Infant', 'price' => 0, 'requires_seat' => false,
        ]);

        $html = Livewire::test(TripDetails::class, ['tenant' => $f['tenant'], 'tripTemplate' => $f['template']])->html();

        $this->assertStringContainsString('تفاصيل الأسعار', $html);
        $this->assertStringContainsString('Adult', $html);
        $this->assertStringContainsString('5,000', $html);
        $this->assertStringContainsString('Infant', $html);
        $this->assertStringContainsString('مجاناً', $html);
    }

    public function test_trip_details_hides_the_price_table_when_no_categories_exist(): void
    {
        $f = $this->makeFixture('5');

        $html = Livewire::test(TripDetails::class, ['tenant' => $f['tenant'], 'tripTemplate' => $f['template']])->html();

        $this->assertStringNotContainsString('تفاصيل الأسعار', $html);
    }

    // ------------------------------------------------------------------
    // Item 3: richer payment-step order summary
    // ------------------------------------------------------------------

    public function test_payment_step_summary_shows_trip_title_and_dates(): void
    {
        $f = $this->makeFixture('6');
        TripPassengerCategory::create([
            'tenant_id' => $f['tenant']->id, 'trip_instance_id' => $f['instance']->id,
            'name' => 'Adult', 'price' => 5000, 'requires_seat' => true,
        ]);

        $html = Livewire::test(CheckoutWizard::class, ['tenant' => $f['tenant'], 'tripInstance' => $f['instance']])
            ->set('currentStep', 4)
            ->html();

        $this->assertStringContainsString('Phase F Trip', $html);
        $this->assertStringContainsString($f['instance']->start_date->format('d M Y'), $html);
    }

    // ------------------------------------------------------------------
    // Item 4: "my bookings" link on the confirmation page
    // ------------------------------------------------------------------

    public function test_booking_success_page_links_to_my_bookings(): void
    {
        $f = $this->makeFixture('7');
        $booking = Booking::create([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'pnr' => 'ZTR-PHASEF07',
            'currency' => 'USD',
            'booking_status' => \App\Enums\BookingStatus::Confirmed,
            'payment_status' => \App\Enums\PaymentStatus::Unpaid,
            'payment_type' => \App\Enums\PaymentType::FULL,
            'grand_total' => 500000,
            'total_paid' => 0,
            'balance_due' => 500000,
            'snapshot_trip_title' => $f['template']->title,
            'snapshot_start_date' => $f['instance']->start_date,
            'snapshot_end_date' => $f['instance']->end_date,
        ]);

        $response = $this->get(route('booking.success', ['tenant' => $f['tenant']->slug, 'uuid' => $booking->uuid]));

        $response->assertOk();
        $response->assertSee('إدارة حجوزاتي');
        $response->assertSee(route('storefront.my-bookings', ['tenant' => $f['tenant']->slug]), false);
    }

    // ------------------------------------------------------------------
    // Item 5: itinerary_data column + admin field actually functioning
    // ------------------------------------------------------------------

    public function test_trip_details_renders_populated_itinerary_data(): void
    {
        $f = $this->makeFixture('8');
        $f['template']->update(['itinerary_data' => [
            ['title' => 'اليوم الأول', 'description' => 'الوصول والاستقبال.'],
            ['title' => 'اليوم الثاني', 'description' => 'استرخاء حر.'],
        ]]);

        $html = Livewire::test(TripDetails::class, ['tenant' => $f['tenant'], 'tripTemplate' => $f['template']])->html();

        $this->assertStringNotContainsString('لم يتم إضافة مسار تفصيلي', $html);
        $this->assertStringContainsString('اليوم الأول', $html);
        $this->assertStringContainsString('اليوم الثاني', $html);
    }

    public function test_trip_details_shows_empty_state_when_itinerary_data_is_null(): void
    {
        $f = $this->makeFixture('9');

        $html = Livewire::test(TripDetails::class, ['tenant' => $f['tenant'], 'tripTemplate' => $f['template']])->html();

        $this->assertStringContainsString('لم يتم إضافة مسار تفصيلي', $html);
    }

    public function test_admin_can_save_itinerary_data_through_trip_template_resource(): void
    {
        $f = $this->makeFixture('10');

        Role::firstOrCreate(['name' => 'agency_admin']);
        Permission::firstOrCreate(['name' => 'panel_access_placeholder']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $admin = User::create(['name' => 'Admin', 'phone' => '0791199010']);
        $admin->tenants()->attach($f['tenant']);
        setPermissionsTeamId($f['tenant']->id);
        $admin->assignRole('agency_admin');

        $this->actingAs($admin);
        Filament::setTenant($f['tenant'], true);

        Livewire::test(EditTripTemplate::class, ['record' => $f['template']->getRouteKey()])
            ->fillForm([
                'cover' => [\Illuminate\Http\UploadedFile::fake()->image('cover.jpg')],
                'templatePassengerCategories' => [
                    ['name' => 'Adult', 'price' => 100, 'requires_seat' => true],
                ],
                'itinerary_data' => [
                    ['title' => 'Day 1', 'description' => 'Arrival.'],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEquals(
            [['title' => 'Day 1', 'description' => 'Arrival.']],
            $f['template']->fresh()->itinerary_data
        );
    }
}
