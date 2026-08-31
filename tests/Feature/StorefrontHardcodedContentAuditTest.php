<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Filament\Pages\ManageAgencySettings;
use App\Mail\BookingConfirmedMail;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\TripInstance;
use App\Models\TripPassengerCategory;
use App\Models\TripTemplate;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Regression coverage for docs/HARDCODED_CONTENT_AUDIT.md: every storefront-facing piece of
 * business content that was hardcoded in Blade/PHP and is now sourced from ManageAgencySettings
 * (agency_tagline, meta_description, hero_headline, hero_subheading, trips_section_eyebrow,
 * trips_section_title, the logo/hero_image media collections), plus the correctness bugs found
 * during the same sweep (dead static logo asset, "زتارة" hardcoded into another tenant's
 * checkout/booking-success screens, the wrong `whatsapp` settings key, the wrong
 * `tenant_slug` route parameter in the booking-confirmation email).
 *
 * Every new field must (a) be settable through ManageAgencySettings exactly like every other
 * field on that page, (b) actually change what a real request renders, and (c) fall back
 * gracefully to the existing copy when a tenant has never touched it -- no regression for
 * tenants that don't opt in.
 */
class StorefrontHardcodedContentAuditTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{tenant: Tenant, template: TripTemplate, instance: TripInstance}
     */
    private function makeFixture(string $suffix, array $settings = []): array
    {
        $tenant = Tenant::create([
            'name' => "Agency {$suffix}",
            'slug' => "agency-audit-{$suffix}",
            'settings' => $settings,
        ]);
        $template = TripTemplate::create([
            'tenant_id' => $tenant->id, 'title' => 'Trip', 'base_price' => 500, 'is_active' => true,
        ]);
        $instance = TripInstance::create([
            'tenant_id' => $tenant->id, 'trip_template_id' => $template->id,
            'start_date' => now()->addDays(10), 'end_date' => now()->addDays(15),
            'available_seats' => 20, 'status' => 'active',
        ]);
        TripPassengerCategory::create([
            'tenant_id' => $tenant->id, 'trip_instance_id' => $instance->id,
            'name' => 'Adult', 'price' => 500, 'requires_seat' => true,
        ]);

        return compact('tenant', 'template', 'instance');
    }

    private function makeBooking(Tenant $tenant, TripInstance $instance, string $suffix): Booking
    {
        $customer = Customer::create(['tenant_id' => $tenant->id, 'name' => 'Customer', 'phone' => "059{$suffix}"]);

        return Booking::create([
            'tenant_id' => $tenant->id,
            'trip_instance_id' => $instance->id,
            'customer_id' => $customer->id,
            'pnr' => "ZTR-AUDIT{$suffix}",
            'booking_status' => BookingStatus::Confirmed,
            'payment_status' => PaymentStatus::Unpaid,
            'grand_total' => 500,
            'balance_due' => 500,
        ]);
    }

    private function makeAgencyAdmin(Tenant $tenant, string $phone): \App\Models\User
    {
        Role::firstOrCreate(['name' => 'agency_admin']);
        Permission::firstOrCreate(['name' => 'panel_access_placeholder']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = \App\Models\User::create(['name' => 'Admin', 'phone' => $phone]);
        $user->tenants()->attach($tenant);
        setPermissionsTeamId($tenant->id);
        $user->assignRole('agency_admin');

        return $user;
    }

    // ------------------------------------------------------------------
    // Homepage hero + trips section copy
    // ------------------------------------------------------------------

    public function test_homepage_hero_falls_back_to_the_existing_copy_when_unset(): void
    {
        $f = $this->makeFixture('001');

        $response = $this->get(route('storefront.catalog', ['tenant' => $f['tenant']->slug]));

        $response->assertOk();
        $response->assertSee('رحلتك القادمة تبدأ من هنا');
        $response->assertSee('اكتشف أروع الوجهات حول العالم بتجربة حجز فائقة السلاسة والرفاهية.');
    }

    public function test_homepage_hero_uses_the_tenants_configured_copy_when_set(): void
    {
        $f = $this->makeFixture('002', [
            'hero_headline' => 'مغامرتك تبدأ الآن',
            'hero_subheading' => 'عروض حصرية على أفضل الوجهات',
        ]);

        $response = $this->get(route('storefront.catalog', ['tenant' => $f['tenant']->slug]));

        $response->assertOk();
        $response->assertSee('مغامرتك تبدأ الآن');
        $response->assertSee('عروض حصرية على أفضل الوجهات');
        $response->assertDontSee('رحلتك القادمة تبدأ من هنا');
    }

    public function test_trips_section_eyebrow_and_title_are_configurable_with_a_graceful_default(): void
    {
        $default = $this->makeFixture('003');
        $custom = $this->makeFixture('004', [
            'trips_section_eyebrow' => 'الأكثر طلباً',
            'trips_section_title' => 'اختر باقتك المثالية',
        ]);

        $defaultResponse = $this->get(route('storefront.catalog', ['tenant' => $default['tenant']->slug]));
        $defaultResponse->assertSee('الوجهات الرائجة');
        $defaultResponse->assertSee('اختر مغامرتك القادمة');

        $customResponse = $this->get(route('storefront.catalog', ['tenant' => $custom['tenant']->slug]));
        $customResponse->assertSee('الأكثر طلباً');
        $customResponse->assertSee('اختر باقتك المثالية');
        $customResponse->assertDontSee('الوجهات الرائجة');
    }

    public function test_meta_description_is_configurable_and_falls_back_when_unset(): void
    {
        $default = $this->makeFixture('005');
        $custom = $this->makeFixture('006', ['meta_description' => 'أفضل عروض السفر في المدينة']);

        $this->get(route('storefront.catalog', ['tenant' => $default['tenant']->slug]))
            ->assertSee('اكتشف أجمل وجهات السفر مع وكالتنا السياحية', false);

        $this->get(route('storefront.catalog', ['tenant' => $custom['tenant']->slug]))
            ->assertSee('أفضل عروض السفر في المدينة', false);
    }

    // ------------------------------------------------------------------
    // Footer tagline + logo
    // ------------------------------------------------------------------

    public function test_footer_tagline_is_configurable_with_a_graceful_default(): void
    {
        $default = $this->makeFixture('007');
        $custom = $this->makeFixture('008', ['agency_tagline' => 'رفيقك في كل رحلة']);

        $this->get(route('storefront.catalog', ['tenant' => $default['tenant']->slug]))
            ->assertSee('نقدم لك تجارب سفر مصممة بعناية لتلبي طموحك في اكتشاف العالم برفاهية مطلقة وخدمة لا تُضاهى.');

        $this->get(route('storefront.catalog', ['tenant' => $custom['tenant']->slug]))
            ->assertSee('رفيقك في كل رحلة');
    }

    public function test_header_logo_falls_back_to_the_tenant_name_and_never_references_the_missing_static_asset(): void
    {
        $f = $this->makeFixture('009');

        $response = $this->get(route('storefront.catalog', ['tenant' => $f['tenant']->slug]));

        $response->assertOk();
        $response->assertSee($f['tenant']->name);
        // The old bug: a static asset that doesn't exist anywhere in the repo, ignoring any
        // uploaded tenant logo entirely.
        $response->assertDontSee('images/logo.png');
    }

    public function test_header_logo_uses_the_tenants_uploaded_logo_when_present(): void
    {
        $f = $this->makeFixture('010');
        $f['tenant']->addMedia(UploadedFile::fake()->image('logo.png', 200, 200))->toMediaCollection('logo');
        $logoUrl = $f['tenant']->fresh()->getFirstMediaUrl('logo');

        $response = $this->get(route('storefront.catalog', ['tenant' => $f['tenant']->slug]));

        $response->assertOk();
        $response->assertSee($logoUrl, false);
    }

    // ------------------------------------------------------------------
    // "زتارة" hardcoded into another tenant's checkout screens
    // ------------------------------------------------------------------

    public function test_checkout_welcomes_the_customer_with_the_real_tenant_name_not_a_hardcoded_brand(): void
    {
        $f = $this->makeFixture('011');

        $response = $this->get(route('storefront.checkout', [
            'tenant' => $f['tenant']->slug, 'tripInstance' => $f['instance']->id,
        ]));

        $response->assertOk();
        $response->assertSee('مرحباً بك في '.$f['tenant']->name);
        $response->assertDontSee('مرحباً بك في زتارة');
    }

    public function test_checkout_disclaimer_references_the_real_tenant_name_not_a_hardcoded_brand(): void
    {
        $f = $this->makeFixture('012');

        $response = $this->get(route('storefront.checkout', [
            'tenant' => $f['tenant']->slug, 'tripInstance' => $f['instance']->id,
        ]));

        $response->assertOk();
        $response->assertSee('سياسة الإلغاء الخاصة بـ'.$f['tenant']->name);
        $response->assertDontSee('سياسة الإلغاء الخاصة بزتارة');
    }

    // ------------------------------------------------------------------
    // booking-success: wrong settings key + hardcoded brand name in the WhatsApp deep link
    // ------------------------------------------------------------------

    public function test_booking_success_whatsapp_link_reads_the_correct_settings_key(): void
    {
        $f = $this->makeFixture('013', ['whatsapp_number' => '+970599555666']);
        $booking = $this->makeBooking($f['tenant'], $f['instance'], '013');

        $response = $this->get(route('booking.success', [
            'tenant' => $f['tenant']->slug, 'uuid' => $booking->uuid,
        ]));

        $response->assertOk();
        $response->assertSee('wa.me/970599555666', false);
    }

    public function test_booking_success_whatsapp_link_falls_back_to_the_same_placeholder_used_elsewhere_in_the_app(): void
    {
        $f = $this->makeFixture('014');
        $booking = $this->makeBooking($f['tenant'], $f['instance'], '014');

        $response = $this->get(route('booking.success', [
            'tenant' => $f['tenant']->slug, 'uuid' => $booking->uuid,
        ]));

        $response->assertOk();
        // Same fallback number the header/nav WhatsApp links already use (previously this one
        // link alone used a different, unrelated placeholder: '1234567890').
        $response->assertSee('wa.me/970599000000', false);
    }

    public function test_booking_success_whatsapp_prefilled_message_uses_the_real_tenant_name(): void
    {
        $f = $this->makeFixture('015');
        $booking = $this->makeBooking($f['tenant'], $f['instance'], '015');

        $response = $this->get(route('booking.success', [
            'tenant' => $f['tenant']->slug, 'uuid' => $booking->uuid,
        ]));

        $response->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString(urlencode('مرحباً '.$f['tenant']->name), $html);
        $this->assertStringNotContainsString(urlencode('مرحباً زتارة'), $html);
    }

    // ------------------------------------------------------------------
    // booking-confirmed email: agency_tagline + the wrong route parameter
    // ------------------------------------------------------------------

    public function test_booking_confirmed_email_falls_back_to_the_existing_tagline_when_unset(): void
    {
        $f = $this->makeFixture('016');
        $booking = $this->makeBooking($f['tenant'], $f['instance'], '016');
        $booking->load('tenant', 'customer', 'tripInstance.tripTemplate');

        $html = (new BookingConfirmedMail($booking, 'رسالة تأكيد'))->render();

        $this->assertStringContainsString('اكتشف العالم بالفخامة التي تستحقها', $html);
    }

    public function test_booking_confirmed_email_uses_the_tenants_configured_tagline(): void
    {
        $f = $this->makeFixture('017', ['agency_tagline' => 'وجهتك، رحلتك، معنا']);
        $booking = $this->makeBooking($f['tenant'], $f['instance'], '017');
        $booking->load('tenant', 'customer', 'tripInstance.tripTemplate');

        $html = (new BookingConfirmedMail($booking, 'رسالة تأكيد'))->render();

        $this->assertStringContainsString('وجهتك، رحلتك، معنا', $html);
    }

    public function test_booking_confirmed_email_cta_link_uses_the_real_route_parameter_and_does_not_throw(): void
    {
        $f = $this->makeFixture('018');
        $booking = $this->makeBooking($f['tenant'], $f['instance'], '018');
        $booking->load('tenant', 'customer', 'tripInstance.tripTemplate');

        // The old bug (`tenant_slug` instead of `tenant`) throws a missing-route-parameter
        // exception the moment the mailable is rendered -- rendering successfully at all is
        // the regression guard.
        $html = (new BookingConfirmedMail($booking, 'رسالة تأكيد'))->render();

        $expectedUrl = route('storefront.catalog', ['tenant' => $f['tenant']->slug]);
        $this->assertStringContainsString($expectedUrl, $html);
    }

    // ------------------------------------------------------------------
    // ManageAgencySettings: every new field is admin-settable end to end
    // ------------------------------------------------------------------

    public function test_agency_admin_can_save_the_new_content_fields_through_manage_agency_settings(): void
    {
        $f = $this->makeFixture('019');
        $admin = $this->makeAgencyAdmin($f['tenant'], '0599000019');

        $this->actingAs($admin);
        Filament::setTenant($f['tenant'], true);

        Livewire::test(ManageAgencySettings::class)
            ->fillForm([
                'agency_tagline' => 'شريكك المثالي للسفر',
                'meta_description' => 'اكتشف رحلاتنا المميزة',
                'hero_headline' => 'انطلق معنا',
                'hero_subheading' => 'رحلات لا تُنسى',
                'trips_section_eyebrow' => 'مختارات الموسم',
                'trips_section_title' => 'اختر رحلتك',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $settings = $f['tenant']->fresh()->settings;
        $this->assertSame('شريكك المثالي للسفر', $settings['agency_tagline']);
        $this->assertSame('اكتشف رحلاتنا المميزة', $settings['meta_description']);
        $this->assertSame('انطلق معنا', $settings['hero_headline']);
        $this->assertSame('رحلات لا تُنسى', $settings['hero_subheading']);
        $this->assertSame('مختارات الموسم', $settings['trips_section_eyebrow']);
        $this->assertSame('اختر رحلتك', $settings['trips_section_title']);
    }

    public function test_agency_admin_can_upload_a_logo_through_manage_agency_settings(): void
    {
        $f = $this->makeFixture('020');
        $admin = $this->makeAgencyAdmin($f['tenant'], '0599000020');

        $this->actingAs($admin);
        Filament::setTenant($f['tenant'], true);

        Livewire::test(ManageAgencySettings::class)
            ->fillForm([
                'logo' => [UploadedFile::fake()->image('logo.png', 200, 200)],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($f['tenant']->fresh()->hasMedia('logo'));
    }

    public function test_agency_admin_can_upload_a_hero_image_through_manage_agency_settings(): void
    {
        $f = $this->makeFixture('021');
        $admin = $this->makeAgencyAdmin($f['tenant'], '0599000021');

        $this->actingAs($admin);
        Filament::setTenant($f['tenant'], true);

        Livewire::test(ManageAgencySettings::class)
            ->fillForm([
                'hero_image' => [UploadedFile::fake()->image('hero.jpg', 1600, 900)],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $tenant = $f['tenant']->fresh();
        $this->assertTrue($tenant->hasMedia('hero_image'));
        $this->assertTrue($tenant->getFirstMedia('hero_image')->hasGeneratedConversion('hero'));
    }

    public function test_saving_settings_without_a_new_logo_does_not_wipe_out_an_existing_one(): void
    {
        $f = $this->makeFixture('022');
        $f['tenant']->addMedia(UploadedFile::fake()->image('logo.png', 200, 200))->toMediaCollection('logo');
        $admin = $this->makeAgencyAdmin($f['tenant'], '0599000022');

        $this->actingAs($admin);
        Filament::setTenant($f['tenant'], true);

        Livewire::test(ManageAgencySettings::class)
            ->fillForm(['agency_tagline' => 'تحديث بسيط'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($f['tenant']->fresh()->hasMedia('logo'));
    }
}
