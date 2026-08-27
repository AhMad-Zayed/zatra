<?php

namespace Tests\Feature;

use App\Livewire\CheckoutWizard;
use App\Livewire\Storefront\MyBookings;
use App\Livewire\StorefrontCatalog;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\TripInstance;
use App\Models\TripPassengerCategory;
use App\Models\TripTemplate;
use App\Services\CreateBookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regression coverage for the storefront "quick win" items from docs/STOREFRONT_UX_AUDIT.md that
 * are meaningfully testable server-side. The countdown-timer flash fix (Quick Win #6) is pure
 * Alpine/JS behavior with no server round-trip to assert on -- it was live-verified in a real
 * browser instead (confirmed the timer never renders "0:00": at the first moment the element
 * exists in the DOM it already shows the real remaining time). The logo fallback (Quick Win #3)
 * turned out, on live inspection, to already work correctly -- no fix was needed there.
 */
class StorefrontQuickWinsTest extends TestCase
{
    use RefreshDatabase;

    private function makeFixture(): array
    {
        $tenant = Tenant::create(['name' => 'Agency QuickWins', 'slug' => 'agency-quickwins']);
        $template = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'QuickWins Trip', 'base_price' => 500, 'is_active' => true]);
        $instance = TripInstance::create([
            'tenant_id' => $tenant->id, 'trip_template_id' => $template->id,
            'start_date' => now()->addDays(20), 'end_date' => now()->addDays(25),
            'available_seats' => 20, 'status' => 'active',
        ]);
        $category = TripPassengerCategory::create([
            'tenant_id' => $tenant->id, 'trip_instance_id' => $instance->id,
            'name' => 'Adult', 'price' => 500, 'requires_seat' => true,
        ]);

        return compact('tenant', 'instance', 'category');
    }

    public function test_invalid_phone_shows_a_human_label_not_the_raw_field_path(): void
    {
        $f = $this->makeFixture();

        Livewire::test(CheckoutWizard::class, ['tenant' => $f['tenant'], 'tripInstance' => $f['instance']])
            ->set('form.passengers.0.first_name', 'Test')
            ->set('form.passengers.0.last_name', 'Passenger')
            ->set('form.email', 'quickwin1@example.com')
            ->set('form.phone', 'not-a-phone-number')
            ->call('submitLeadCapture')
            ->assertHasErrors(['form.phone'])
            // "form.phone" itself legitimately appears in the raw HTML as part of the input's own
            // wire:model attribute -- assert against the specific error text instead of a blanket
            // page-wide absence check.
            ->assertDontSeeHtml('صيغة form.phone غير صحيحة')
            ->assertSee('صيغة رقم الجوال غير صحيحة');
    }

    public function test_catalog_search_bar_no_longer_has_a_non_functional_guests_field(): void
    {
        $f = $this->makeFixture();

        Livewire::test(StorefrontCatalog::class, ['tenant' => $f['tenant']])
            ->assertDontSee('الضيوف');
    }

    public function test_ticket_locked_button_explains_why_when_balance_is_due(): void
    {
        $f = $this->makeFixture();
        $customer = Customer::create(['tenant_id' => $f['tenant']->id, 'name' => 'Test Customer', 'phone' => '+966500000077']);

        app(CreateBookingService::class)->execute([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'customer_id' => $customer->id,
            'passengersData' => [
                ['trip_passenger_category_id' => $f['category']->id, 'first_name' => 'Test', 'last_name' => 'Passenger'],
            ],
        ]);

        Auth::guard('customer')->login($customer);

        Livewire::test(MyBookings::class, ['tenant' => $f['tenant']])
            ->assertSee('تذكرة مقفلة')
            ->assertSee('ستتوفر التذكرة بعد إتمام الدفع');
    }
}
