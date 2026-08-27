<?php

namespace Tests\Feature;

use App\Livewire\CheckoutWizard;
use App\Models\Hotel;
use App\Models\RoomType;
use App\Models\Tenant;
use App\Models\TripInstance;
use App\Models\TripPassengerCategory;
use App\Models\TripStayLeg;
use App\Models\TripStayLegHotelOption;
use App\Models\TripTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regression coverage for a live-reproduced storefront bug (docs/STOREFRONT_UX_AUDIT.md,
 * Friction Point #5): Step 3's room selector showed a bare quantity stepper with no price
 * anywhere, and Step 4's order summary folded the resulting room charge into a line literally
 * labeled "الركاب" (Passengers) -- a customer could never see what a room actually cost, even
 * after reaching payment. Live-confirmed: selecting 1 triple room (shared) silently added $90 to
 * a total displayed only as "الركاب (3)".
 *
 * Fixed by showing each room type's real per-room price (shared and single) directly in the Step
 * 3 selector, and splitting Step 4's summary into independent "الركاب" / "الغرف" / "الإضافات"
 * lines instead of one combined figure.
 */
class CheckoutRoomPriceTransparencyTest extends TestCase
{
    use RefreshDatabase;

    private function makeFixtureWithRoomBooking(): array
    {
        $tenant = Tenant::create([
            'name' => 'Agency Rooms', 'slug' => 'agency-rooms',
            'settings' => ['room_booking_enabled' => true],
        ]);
        $template = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'Room Trip', 'base_price' => 500, 'is_active' => true]);
        $instance = TripInstance::create([
            'tenant_id' => $tenant->id, 'trip_template_id' => $template->id,
            'start_date' => now()->addDays(20), 'end_date' => now()->addDays(25),
            'available_seats' => 20, 'status' => 'active',
        ]);
        $category = TripPassengerCategory::create([
            'tenant_id' => $tenant->id, 'trip_instance_id' => $instance->id,
            'name' => 'Adult', 'price' => 500, 'requires_seat' => true,
        ]);

        $hotel = Hotel::create(['tenant_id' => $tenant->id, 'name' => 'Test Hotel']);
        $leg = TripStayLeg::create([
            'tenant_id' => $tenant->id, 'trip_instance_id' => $instance->id, 'label' => 'الإقامة',
            'start_date' => now()->addDays(20), 'end_date' => now()->addDays(25),
        ]);
        $option = TripStayLegHotelOption::create([
            'tenant_id' => $tenant->id, 'trip_stay_leg_id' => $leg->id, 'hotel_id' => $hotel->id,
            'label' => 'الخيار القياسي', 'is_active' => true,
        ]);
        $roomType = RoomType::create([
            'tenant_id' => $tenant->id, 'trip_stay_leg_hotel_option_id' => $option->id,
            'name' => 'غرفة ثلاثية', 'is_active' => true,
            'capacity_per_room' => 3,
            'room_count' => 5,
            'price_adjustment_shared' => 30,
            'price_adjustment_single_supplement' => 15,
        ]);

        return compact('tenant', 'instance', 'category', 'roomType');
    }

    public function test_step3_shows_each_room_types_real_shared_and_single_price(): void
    {
        $f = $this->makeFixtureWithRoomBooking();

        // shared = price_adjustment_shared * capacity_per_room = 30 * 3 = 90
        // single = price_adjustment_shared + price_adjustment_single_supplement = 30 + 15 = 45
        Livewire::test(CheckoutWizard::class, ['tenant' => $f['tenant'], 'tripInstance' => $f['instance']])
            ->assertSee('90')
            ->assertSee('45');
    }

    public function test_step4_summary_splits_passengers_and_rooms_into_separate_lines(): void
    {
        $f = $this->makeFixtureWithRoomBooking();

        $c = Livewire::test(CheckoutWizard::class, ['tenant' => $f['tenant'], 'tripInstance' => $f['instance']])
            ->set('form.passengers.0.first_name', 'Test')
            ->set('form.passengers.0.last_name', 'Passenger')
            ->set('form.email', 'roomtest@example.com')
            ->call('submitLeadCapture')
            ->set('form.passengers.0.trip_passenger_category_id', $f['category']->id)
            ->call('submitPassengers')
            ->call('updateRoomSelectionQuantity', $f['roomType']->id, 1)
            ->assertSet('passengersSubtotal', 500.0)
            ->assertSet('roomsSubtotal', 90.0) // shared occupancy is the default
            ->assertSet('grandTotal', 590.0);

        $c->call('submitAddons')
            ->assertSeeHtml('<span>الغرف</span>')
            ->assertSee('590');
    }

    public function test_step4_summary_omits_the_rooms_line_when_no_room_is_selected(): void
    {
        $f = $this->makeFixtureWithRoomBooking();

        Livewire::test(CheckoutWizard::class, ['tenant' => $f['tenant'], 'tripInstance' => $f['instance']])
            ->set('form.passengers.0.first_name', 'Test')
            ->set('form.passengers.0.last_name', 'Passenger')
            ->set('form.email', 'roomtest2@example.com')
            ->call('submitLeadCapture')
            ->set('form.passengers.0.trip_passenger_category_id', $f['category']->id)
            ->call('submitPassengers')
            ->call('submitAddons')
            ->assertSet('roomsSubtotal', 0.0)
            // "الغرف" alone would also match Step 3's own "اختر الغرف (اختياري)" section
            // heading (every step's markup is always present in the page; Alpine just toggles
            // visibility) -- assert against the Step 4 summary row specifically instead.
            ->assertDontSeeHtml('<span>الغرف</span>');
    }
}
