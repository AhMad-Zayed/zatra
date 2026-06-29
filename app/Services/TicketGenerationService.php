<?php

namespace App\Services;

use App\Models\Booking;
use Spatie\LaravelPdf\Facades\Pdf;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use Illuminate\Support\Facades\Storage;

class TicketGenerationService
{
    /**
     * Generates a branded PDF ticket, stores it securely, and attaches it to the Booking.
     */
    public function generateAndStoreTicket(Booking $booking): string
    {
        // 1. Generate the Verification QR Code (SVG format string)
        $qrCodeSvg = QrCode::size(120)
            ->style('round')
            ->generate($booking->pnr_code ?? $booking->id);

        // 2. Aggregate View Data
        $data = [
            'booking' => $booking,
            'qrCode' => $qrCodeSvg,
            'tenant' => $booking->tenant, // Contains Agency Logo, Colors, Terms
            'trip' => $booking->tripInstance,
            'passengers' => $booking->passengers,
        ];

        // 3. Define Secure Storage Path
        $fileName = "tickets/{$booking->tenant_id}/ticket_{$booking->id}.pdf";
        $absolutePath = storage_path('app/private/' . $fileName);

        if (!file_exists(dirname($absolutePath))) {
            mkdir(dirname($absolutePath), 0755, true);
        }

        // 4. Generate the PDF
        Pdf::view('pdf.ticket-template', $data)
            ->format('A4')
            ->margins(10, 10, 10, 10)
            ->save($absolutePath);

        // 5. Attach via Spatie Media Library
        $booking->addMedia($absolutePath)
                ->toMediaCollection('tickets', 'private');

        return $absolutePath;
    }
}
