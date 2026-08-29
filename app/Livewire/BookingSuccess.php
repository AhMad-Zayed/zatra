<?php

namespace App\Livewire;

use Livewire\Component;
use App\Models\Booking;
use App\Models\Tenant;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use function Spatie\LaravelPdf\Support\pdf;
use Spatie\Browsershot\Browsershot;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

#[Layout('components.layouts.storefront')]
class BookingSuccess extends Component
{
    public Booking $booking;
    public Tenant $tenant;
    public string $qrCodeSvg;

    public function mount(Tenant $tenant, $uuid)
    {
        $this->tenant = $tenant;
        $this->booking = Booking::where('uuid', $uuid)
            ->with(['passengers.tripPassengerCategory', 'tripInstance.tripTemplate', 'tenant', 'bookingAddons.tripAddon'])
            ->firstOrFail();
            
        $this->qrCodeSvg = QrCode::format('svg')->size(150)->generate($this->booking->pnr);
    }

    public function downloadPdf()
    {
        $booking = $this->booking;
        // pdf.ticket-template actually references $tenant, $qrCode, $trip (not $tripInstance),
        // and $passengers -- confirmed against TicketGenerationService::generateAndStoreTicket(),
        // the template's other real caller, which already passes exactly this set correctly.
        // This method previously passed only 'booking' and 'tripInstance' (a name the template
        // never uses at all), so every one of $tenant/$qrCode/$trip/$passengers rendered as an
        // undefined variable -- $tenant specifically threw first, live-reproduced and reported
        // via a customer download attempt, but every other one was equally broken and would have
        // failed the same way immediately after.
        $tenant = $this->tenant;
        $qrCode = $this->qrCodeSvg;

        return response()->streamDownload(function () use ($booking, $tenant, $qrCode) {
            echo pdf()
                ->withBrowsershot(function (Browsershot $browsershot) {
                    if (config('services.browsershot.node_path')) {
                        $browsershot->setNodeBinary(config('services.browsershot.node_path'));
                    }
                    if (config('services.browsershot.npm_path')) {
                        $browsershot->setNpmBinary(config('services.browsershot.npm_path'));
                    }
                })
                ->view('pdf.ticket-template', [
                    'booking' => $booking,
                    'tenant' => $tenant,
                    'qrCode' => $qrCode,
                    'trip' => $booking->tripInstance,
                    'passengers' => $booking->passengers,
                ])
                ->name('Zatara-Ticket-' . $booking->pnr . '.pdf')
                ->format('a4')
                ->generatePdfContent();
        }, 'Zatara-Ticket-' . $booking->pnr . '.pdf');
    }

    public function render()
    {
        $this->booking->loadMissing(['passengers.tripPassengerCategory', 'tripInstance.tripTemplate']);
        return view('livewire.booking-success');
    }
}
