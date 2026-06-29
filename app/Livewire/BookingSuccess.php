<?php

namespace App\Livewire;

use Livewire\Component;
use App\Models\Booking;
use App\Models\Tenant;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use function Spatie\LaravelPdf\Support\pdf;
use Spatie\Browsershot\Browsershot;

#[Layout('components.layouts.storefront')]
class BookingSuccess extends Component
{
    public Booking $booking;
    public Tenant $tenant;

    public function mount(Tenant $tenant, $uuid)
    {
        $this->tenant = $tenant;
        $this->booking = Booking::where('uuid', $uuid)
            ->with(['passengers.tripPassengerCategory', 'tripInstance.tripTemplate', 'tenant', 'bookingAddons.tripAddon'])
            ->firstOrFail();
    }

    public function downloadPdf()
    {
        $booking = $this->booking;

        return response()->streamDownload(function () use ($booking) {
            echo pdf()
                ->withBrowsershot(function (Browsershot $browsershot) {
                    $browsershot->setNodeBinary('/Users/ahmadzayed/.nvm/versions/node/v22.18.0/bin/node');
                    $browsershot->setNpmBinary('/Users/ahmadzayed/.nvm/versions/node/v22.18.0/bin/npm');
                })
                ->view('pdf.ticket', ['booking' => $booking, 'tripInstance' => $booking->tripInstance])
                ->name('Zatara-Ticket-' . $booking->pnr . '.pdf')
                ->format('a4')
                ->generatePdfContent();
        }, 'Zatara-Ticket-' . $booking->pnr . '.pdf');
    }

    public function render()
    {
        return view('livewire.booking-success');
    }
}
