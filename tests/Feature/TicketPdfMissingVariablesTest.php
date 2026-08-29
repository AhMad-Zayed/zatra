<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingAddon;
use App\Models\Customer;
use App\Models\Passenger;
use App\Models\Tenant;
use App\Models\TripAddon;
use App\Models\TripInstance;
use App\Models\TripPassengerCategory;
use App\Models\TripTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * URGENT bug fix, live-reproduced via a real Ignition stack trace the stakeholder provided:
 * clicking "تحميل الإيصال" on the booking-success page threw "Undefined variable $tenant" in
 * resources/views/pdf/ticket-template.blade.php.
 *
 * Root cause: App\Livewire\BookingSuccess::downloadPdf() called
 * Pdf::view('pdf.ticket-template', ['booking' => $booking, 'tripInstance' => $booking->tripInstance])
 * -- the component's own real $tenant property (present in its Livewire snapshot) was simply
 * never added to that array. Auditing the rest of the template against this array turned up three
 * more of the exact same class of bug, not just $tenant: $qrCode (the component has
 * $this->qrCodeSvg, under a different name, also never passed), $trip (the template never uses
 * "tripInstance" at all -- confirmed against TicketGenerationService::generateAndStoreTicket(),
 * the template's other real caller, which already passes the correct 'trip' key), and $passengers
 * (never extracted from $booking->passengers at all).
 *
 * TicketGenerationService (the template's other caller) already passes booking/tenant/qrCode/
 * trip/passengers correctly -- confirmed via direct comparison, nothing to fix there.
 *
 * Two further template-level bugs found and fixed in the same pass, independent of either
 * caller: $booking->addons (no such relation on Booking, only bookingAddons()) and
 * $addon->addon_name (no such column) -- both silently resolved to nothing rendering rather than
 * crashing (Eloquent's magic __get on an undefined relation returns null, not an exception), so
 * purchased add-ons never appeared on a single generated ticket regardless of which caller
 * generated it.
 *
 * Fixed entirely in App\Livewire\BookingSuccess::downloadPdf() and the shared blade template --
 * unrelated to the earlier Browsershot node/npm binary-path fix from earlier in this session
 * (that was a missing-binary environment issue; this is a plain missing-view-data bug, confirmed
 * by reproducing it and reading the exact "Undefined variable" trace, not a Browsershot process
 * failure).
 *
 * This test renders the real view with the exact data array downloadPdf() now builds (not the
 * full Browsershot HTML->PDF conversion itself, which needs a real Node/Chrome binary and has no
 * existing test coverage anywhere in this codebase to mirror) -- it reliably catches the actual
 * bug class (undefined variables reaching the view) without depending on that external toolchain.
 * The full real PDF -- Browsershot conversion included -- was live-verified separately: a real
 * checkout, real "تحميل الإيصال" click, a real downloaded PDF opened and visually confirmed to
 * show the real tenant name, a real QR code, real trip dates, and the real passenger's name.
 */
class TicketPdfMissingVariablesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{tenant: Tenant, booking: Booking}
     */
    private function makeFixture(string $suffix): array
    {
        $tenant = Tenant::create(['name' => "Agency {$suffix}", 'slug' => "agency-pdf-{$suffix}"]);
        $customer = Customer::create(['tenant_id' => $tenant->id, 'name' => 'PDF Test Customer', 'phone' => "0591{$suffix}"]);
        $template = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'PDF Test Trip', 'base_price' => 100]);
        $instance = TripInstance::create([
            'tenant_id' => $tenant->id, 'trip_template_id' => $template->id,
            'start_date' => now()->addDays(10), 'end_date' => now()->addDays(15),
            'available_seats' => 10, 'status' => 'active',
        ]);
        $category = TripPassengerCategory::create([
            'tenant_id' => $tenant->id, 'trip_instance_id' => $instance->id,
            'name' => 'Adult', 'price' => 100, 'requires_seat' => true,
        ]);
        $addon = TripAddon::create([
            'tenant_id' => $tenant->id, 'trip_instance_id' => $instance->id,
            'name' => 'Travel Insurance', 'price' => 20, 'max_quantity' => 5,
        ]);

        $booking = Booking::create([
            'tenant_id' => $tenant->id,
            'trip_instance_id' => $instance->id,
            'customer_id' => $customer->id,
            'pnr' => 'ZTR-PDFTEST' . $suffix,
            'booking_status' => \App\Enums\BookingStatus::Confirmed,
            'payment_status' => \App\Enums\PaymentStatus::Paid,
        ]);
        $passenger = Passenger::create([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'trip_passenger_category_id' => $category->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'document_type' => 'passport',
            'document_number' => 'P1234567',
        ]);
        BookingAddon::create([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'passenger_id' => $passenger->id,
            'trip_addon_id' => $addon->id,
            'quantity' => 1,
            'price_at_booking' => 20,
        ]);

        $booking->load(['passengers.tripPassengerCategory', 'tripInstance.tripTemplate', 'bookingAddons.tripAddon']);

        return compact('tenant', 'booking');
    }

    public function test_the_template_no_longer_throws_undefined_variable_with_the_data_downloadpdf_now_passes(): void
    {
        $f = $this->makeFixture('001');

        // The exact array shape App\Livewire\BookingSuccess::downloadPdf() builds after the fix.
        $html = view('pdf.ticket-template', [
            'booking' => $f['booking'],
            'tenant' => $f['tenant'],
            'qrCode' => '<svg></svg>',
            'trip' => $f['booking']->tripInstance,
            'passengers' => $f['booking']->passengers,
        ])->render();

        $this->assertNotEmpty($html);
    }

    public function test_the_rendered_ticket_shows_the_real_tenant_name(): void
    {
        $f = $this->makeFixture('002');

        $html = view('pdf.ticket-template', [
            'booking' => $f['booking'],
            'tenant' => $f['tenant'],
            'qrCode' => '<svg></svg>',
            'trip' => $f['booking']->tripInstance,
            'passengers' => $f['booking']->passengers,
        ])->render();

        $this->assertStringContainsString($f['tenant']->name, $html);
    }

    public function test_the_rendered_ticket_shows_the_real_passenger_name_and_pnr(): void
    {
        $f = $this->makeFixture('003');

        $html = view('pdf.ticket-template', [
            'booking' => $f['booking'],
            'tenant' => $f['tenant'],
            'qrCode' => '<svg></svg>',
            'trip' => $f['booking']->tripInstance,
            'passengers' => $f['booking']->passengers,
        ])->render();

        $this->assertStringContainsString('John', $html);
        $this->assertStringContainsString('Doe', $html);
        $this->assertStringContainsString($f['booking']->pnr, $html);
    }

    public function test_purchased_addons_actually_render_now_that_the_relation_name_is_correct(): void
    {
        $f = $this->makeFixture('004');

        $html = view('pdf.ticket-template', [
            'booking' => $f['booking'],
            'tenant' => $f['tenant'],
            'qrCode' => '<svg></svg>',
            'trip' => $f['booking']->tripInstance,
            'passengers' => $f['booking']->passengers,
        ])->render();

        $this->assertStringContainsString('Travel Insurance', $html, 'The purchased add-on name must actually appear -- $booking->addons/->addon_name never resolved to real data before this fix.');
    }

    public function test_the_livewire_component_builds_exactly_this_data_shape(): void
    {
        // Source-level proof BookingSuccess::downloadPdf() actually passes every key the view
        // needs -- guards against a future edit silently dropping one of them again.
        $source = file_get_contents(app_path('Livewire/BookingSuccess.php'));

        $this->assertStringContainsString("'tenant' => \$tenant", $source);
        $this->assertStringContainsString("'qrCode' => \$qrCode", $source);
        $this->assertStringContainsString("'trip' => \$booking->tripInstance", $source);
        $this->assertStringContainsString("'passengers' => \$booking->passengers", $source);
    }
}
