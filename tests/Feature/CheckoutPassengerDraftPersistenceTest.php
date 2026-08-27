<?php

namespace Tests\Feature;

use App\Livewire\CheckoutWizard;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\TripInstance;
use App\Models\TripPassengerCategory;
use App\Models\TripTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regression coverage for a live-reproduced storefront bug (docs/STOREFRONT_UX_AUDIT.md,
 * Friction Point #3): a hard refresh at checkout Step 2 silently discarded every typed passenger
 * entry -- including the customer's own name, auto-filled from Step 1 -- while the seat
 * hold/countdown kept ticking unaffected. Fixed by persisting $form->passengers into the PHP
 * session (keyed by trip instance, not by GuestSession, so it also covers logged-in customers)
 * on every passenger field change, and restoring it in mount() when resuming Step 2.
 *
 * Each "refresh" below is simulated the same way a real browser refresh behaves server-side: a
 * brand new Livewire::test(CheckoutWizard::class, ...) call, which runs mount() from scratch
 * against the same underlying test session -- exactly what a fresh HTTP GET to the same checkout
 * URL does.
 */
class CheckoutPassengerDraftPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private function makeFixture(): array
    {
        $tenant = Tenant::create(['name' => 'Agency Draft', 'slug' => 'agency-draft']);
        $template = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'Draft Trip', 'base_price' => 500, 'is_active' => true]);
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

    public function test_guest_lead_capture_name_survives_a_refresh_at_step_2(): void
    {
        $f = $this->makeFixture();

        Livewire::test(CheckoutWizard::class, ['tenant' => $f['tenant'], 'tripInstance' => $f['instance']])
            ->set('form.passengers.0.first_name', 'محمد')
            ->set('form.passengers.0.last_name', 'الاختبار')
            ->set('form.email', 'draft-guest@example.com')
            ->call('submitLeadCapture')
            ->assertSet('currentStep', 2);

        // Simulate a hard refresh: a brand new component mount against the same session.
        Livewire::test(CheckoutWizard::class, ['tenant' => $f['tenant'], 'tripInstance' => $f['instance']])
            ->assertSet('currentStep', 2)
            ->assertSet('form.passengers.0.first_name', 'محمد')
            ->assertSet('form.passengers.0.last_name', 'الاختبار');
    }

    public function test_a_second_passengers_typed_data_survives_a_refresh(): void
    {
        $f = $this->makeFixture();

        Livewire::test(CheckoutWizard::class, ['tenant' => $f['tenant'], 'tripInstance' => $f['instance']])
            ->set('form.passengers.0.first_name', 'محمد')
            ->set('form.passengers.0.last_name', 'الاختبار')
            ->set('form.email', 'draft-guest2@example.com')
            ->call('submitLeadCapture')
            ->call('addPassenger')
            ->set('form.passengers.1.first_name', 'عبدالله')
            ->set('form.passengers.1.last_name', 'الثاني');

        // Simulate a hard refresh.
        Livewire::test(CheckoutWizard::class, ['tenant' => $f['tenant'], 'tripInstance' => $f['instance']])
            ->assertCount('form.passengers', 2)
            ->assertSet('form.passengers.1.first_name', 'عبدالله')
            ->assertSet('form.passengers.1.last_name', 'الثاني');
    }

    public function test_removing_a_passenger_updates_the_persisted_draft_too(): void
    {
        $f = $this->makeFixture();

        Livewire::test(CheckoutWizard::class, ['tenant' => $f['tenant'], 'tripInstance' => $f['instance']])
            ->set('form.passengers.0.first_name', 'محمد')
            ->set('form.passengers.0.last_name', 'الاختبار')
            ->set('form.email', 'draft-guest3@example.com')
            ->call('submitLeadCapture')
            ->assertSet('currentStep', 2)
            ->call('addPassenger')
            ->set('form.passengers.1.first_name', 'مؤقت')
            ->call('removePassenger', 1);

        Livewire::test(CheckoutWizard::class, ['tenant' => $f['tenant'], 'tripInstance' => $f['instance']])
            ->assertCount('form.passengers', 1)
            ->assertSet('form.passengers.0.first_name', 'محمد');
    }

    public function test_a_logged_in_customer_with_no_guest_session_also_keeps_data_across_a_refresh(): void
    {
        $f = $this->makeFixture();
        $customer = Customer::create(['tenant_id' => $f['tenant']->id, 'name' => 'Returning Customer', 'phone' => '+966500000099']);
        Auth::guard('customer')->login($customer);

        Livewire::test(CheckoutWizard::class, ['tenant' => $f['tenant'], 'tripInstance' => $f['instance']])
            ->assertSet('currentStep', 2) // logged-in customers skip lead capture entirely
            ->call('addPassenger')
            ->set('form.passengers.1.first_name', 'ضيف')
            ->set('form.passengers.1.last_name', 'مسجل');

        Livewire::test(CheckoutWizard::class, ['tenant' => $f['tenant'], 'tripInstance' => $f['instance']])
            ->assertCount('form.passengers', 2)
            ->assertSet('form.passengers.1.first_name', 'ضيف');
    }

    public function test_a_completed_booking_clears_the_draft_so_a_later_checkout_starts_clean(): void
    {
        $f = $this->makeFixture();

        Livewire::test(CheckoutWizard::class, ['tenant' => $f['tenant'], 'tripInstance' => $f['instance']])
            ->set('form.passengers.0.first_name', 'محمد')
            ->set('form.passengers.0.last_name', 'مكتمل')
            ->set('form.email', 'draft-complete@example.com')
            ->call('submitLeadCapture')
            ->set('form.passengers.0.trip_passenger_category_id', $f['category']->id)
            ->call('submitPassengers')
            ->call('submitAddons')
            ->set('paymentMethod', 'cash')
            ->set('paymentType', 'full')
            ->call('submitBooking')
            ->assertSet('booking_id', fn ($id) => $id !== null);

        $this->assertNull(session("checkout_passengers_draft_{$f['instance']->id}"));
    }
}
