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
 * URGENT bug fix, live-reproduced: a real checkout attempt (cash payment, "تأكيد الحجز الآن"
 * clicked) crashed with a raw English "Something went wrong while processing your booking."
 * error. storage/logs/laravel.log's real underlying exception:
 * "No query results for model [App\Models\TripPassengerCategory]." -- an Eloquent
 * ModelNotFoundException, confirmed via the exact throwing line
 * (CreateBookingService::execute(), TripPassengerCategory::where('id',
 * $pData['trip_passenger_category_id'])->firstOrFail()) to fire whenever a passenger reaches
 * submitBooking() with trip_passenger_category_id still null.
 *
 * Root cause: BookingForm::rules() had 'trip_passenger_category_id' => ['nullable', ...] --
 * despite a 'trip_passenger_category_id.required' message already existing in
 * BookingForm::messages(), confirming this was always meant to be required and never actually
 * was. Nothing in the customer-facing form ever forced a category to be selected, so a passenger
 * could reach the final payment step (and submitBooking()) with no category at all -- confirmed
 * live via a real second-checkout-attempt-in-the-same-session scenario, where a resumed session's
 * passenger draft didn't carry a category forward.
 *
 * Fixed at the validation layer (BookingForm, not CreateBookingService -- guardrail-protected and
 * confirmed not to need any change: its firstOrFail() is a legitimate defensive check against a
 * value that should never have reached it validation-wise). 'trip_passenger_category_id' is now
 * 'required', so submitPassengers()'s own validateOnly('passengers.*.trip_passenger_category_id')
 * call blocks the customer at Step 2 with the pre-existing, now-finally-reachable
 * "يرجى اختيار باقة المسافر." message, long before submitBooking() could ever be reached with a
 * null value.
 */
class CheckoutMissingPassengerCategoryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{tenant: Tenant, instance: TripInstance, category: TripPassengerCategory}
     */
    private function makeFixture(string $suffix): array
    {
        $tenant = Tenant::create(['name' => "Agency {$suffix}", 'slug' => "agency-nocat-{$suffix}"]);
        $template = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'Trip', 'base_price' => 200, 'is_active' => true]);
        $instance = TripInstance::create([
            'tenant_id' => $tenant->id, 'trip_template_id' => $template->id,
            'start_date' => now()->addDays(15), 'end_date' => now()->addDays(20),
            'available_seats' => 20, 'status' => 'active',
        ]);
        $category = TripPassengerCategory::create([
            'tenant_id' => $tenant->id, 'trip_instance_id' => $instance->id,
            'name' => 'Adult', 'price' => 200, 'requires_seat' => true,
        ]);

        return compact('tenant', 'instance', 'category');
    }

    private function beginCheckout(array $f, string $emailSuffix): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(CheckoutWizard::class, ['tenant' => $f['tenant'], 'tripInstance' => $f['instance']])
            ->set('form.passengers.0.first_name', 'Test')
            ->set('form.passengers.0.last_name', 'Passenger')
            ->set('form.email', "checkout-nocat-{$emailSuffix}@example.com")
            ->call('submitLeadCapture')
            ->assertSet('currentStep', 2);
    }

    // ------------------------------------------------------------------
    // The actual bug: submitting with no category selected
    // ------------------------------------------------------------------

    public function test_submitting_a_passenger_with_no_category_selected_is_blocked_with_a_real_arabic_message_not_a_crash(): void
    {
        $f = $this->makeFixture('001');

        // trip_passenger_category_id deliberately left unset -- this is exactly what happened
        // live (a resumed checkout session whose draft never carried a category forward).
        $this->beginCheckout($f, '001')
            ->call('submitPassengers')
            ->assertHasErrors(['form.passengers.0.trip_passenger_category_id' => 'required'])
            ->assertSee('يرجى اختيار باقة المسافر.')
            ->assertSet('currentStep', 2); // never advances past Step 2
    }

    public function test_the_second_passenger_missing_a_category_is_also_caught_not_just_the_first(): void
    {
        $f = $this->makeFixture('002');

        $this->beginCheckout($f, '002')
            ->set('form.passengers.0.trip_passenger_category_id', $f['category']->id)
            ->call('addPassenger')
            ->set('form.passengers.1.first_name', 'Second')
            ->set('form.passengers.1.last_name', 'Passenger')
            // form.passengers.1.trip_passenger_category_id deliberately left unset.
            ->call('submitPassengers')
            ->assertHasErrors(['form.passengers.1.trip_passenger_category_id' => 'required'])
            ->assertSet('currentStep', 2);
    }

    // ------------------------------------------------------------------
    // The happy path still works end to end (the actual fix doesn't just move the failure)
    // ------------------------------------------------------------------

    public function test_a_real_booking_completes_successfully_once_every_passenger_has_a_category(): void
    {
        $f = $this->makeFixture('003');

        $component = $this->beginCheckout($f, '003')
            ->set('form.passengers.0.trip_passenger_category_id', $f['category']->id)
            ->call('submitPassengers')
            ->assertHasNoErrors()
            ->assertSet('currentStep', 3)
            ->call('submitAddons')
            ->assertSet('currentStep', 4)
            ->set('paymentMethod', 'cash')
            ->call('submitBooking')
            ->assertHasNoErrors();

        $bookingId = $component->get('booking_id');
        $this->assertNotNull($bookingId, 'A real booking must actually be created once a category is selected.');

        $booking = \App\Models\Booking::with('passengers')->find($bookingId);
        $this->assertSame($f['instance']->id, $booking->trip_instance_id);
        $this->assertCount(1, $booking->passengers);
        $this->assertSame($f['category']->id, $booking->passengers->first()->trip_passenger_category_id);
    }
}
