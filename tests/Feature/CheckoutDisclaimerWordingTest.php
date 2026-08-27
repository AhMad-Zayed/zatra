<?php

namespace Tests\Feature;

use App\Livewire\CheckoutWizard;
use App\Models\Tenant;
use App\Models\TripInstance;
use App\Models\TripPassengerCategory;
use App\Models\TripTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regression coverage for a live-reproduced storefront bug (docs/STOREFRONT_UX_AUDIT.md,
 * Friction Point #6): Step 4's disclaimer always promised "سيتم تأكيد المقاعد فور الدفع بنجاح"
 * (seats confirm as soon as payment succeeds) even for cash/transfer, where no payment happens at
 * that moment at all -- the very next screen (booking-success.blade.php) then says the booking is
 * merely "قيد الانتظار" (pending), contradicting the promise seconds earlier. Fixed by making the
 * disclaimer's wording conditional on the selected payment method.
 */
class CheckoutDisclaimerWordingTest extends TestCase
{
    use RefreshDatabase;

    private function makeFixture(): array
    {
        $tenant = Tenant::create(['name' => 'Agency Disclaimer', 'slug' => 'agency-disclaimer']);
        $template = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'Disclaimer Trip', 'base_price' => 500, 'is_active' => true]);
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

    private function reachStep4(array $f, string $email)
    {
        return Livewire::test(CheckoutWizard::class, ['tenant' => $f['tenant'], 'tripInstance' => $f['instance']])
            ->set('form.passengers.0.first_name', 'Test')
            ->set('form.passengers.0.last_name', 'Passenger')
            ->set('form.email', $email)
            ->call('submitLeadCapture')
            ->set('form.passengers.0.trip_passenger_category_id', $f['category']->id)
            ->call('submitPassengers')
            ->call('submitAddons');
    }

    public function test_cash_payment_shows_a_pending_disclaimer_not_an_instant_confirmation_promise(): void
    {
        $f = $this->makeFixture();

        $this->reachStep4($f, 'disclaimer-cash@example.com')
            ->assertSet('paymentMethod', 'cash') // the wizard's own default
            ->assertSee('قيد الانتظار')
            ->assertDontSee('سيتم تأكيد المقاعد فور الدفع بنجاح');
    }

    public function test_bank_transfer_also_shows_the_pending_disclaimer(): void
    {
        $f = $this->makeFixture();

        $this->reachStep4($f, 'disclaimer-transfer@example.com')
            ->set('paymentMethod', 'transfer')
            ->assertSee('قيد الانتظار')
            ->assertDontSee('سيتم تأكيد المقاعد فور الدفع بنجاح');
    }
}
