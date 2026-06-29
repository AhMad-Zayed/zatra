<?php

namespace App\Services\Notifications;

use App\Models\Booking;
use App\Jobs\SendBookingNotificationJob;
use Spatie\LaravelPdf\Facades\Pdf;
use Illuminate\Support\Facades\Storage;

class BookingNotificationService
{
    /**
     * Process booking confirmation notifications.
     * Generates PDF ONCE and dispatches queue jobs.
     */
    public function sendConfirmation(Booking $booking): void
    {
        // QA PATCH: Generate the PDF ONCE in the service layer and save to storage
        $pdfPath = $this->generateAndStoreTicket($booking);
        $attachments = $pdfPath ? [$pdfPath] : [];

        // 1. Fire Queue Job for Email
        SendBookingNotificationJob::dispatch(
            $booking, 
            'email', 
            'تم تأكيد حجزك بنجاح! شكراً لاختيارك رحلاتنا.', 
            $attachments
        );

        // 2. Fire Queue Job for WhatsApp
        SendBookingNotificationJob::dispatch(
            $booking, 
            'whatsapp', 
            "مرحباً {$booking->customer->name}، تم تأكيد حجزك رقم #{$booking->id} بنجاح. نتمنى لك رحلة ممتعة!", 
            $attachments
        );
    }

    /**
     * Generate the E-Ticket PDF and store it in the local disk.
     */
    protected function generateAndStoreTicket(Booking $booking): ?string
    {
        try {
            $booking->loadMissing(['tripInstance.template', 'passengers.tripPricingTier', 'tenant', 'customer']);
            
            $filename = "tickets/booking-{$booking->id}.pdf";
            $fullPath = storage_path("app/public/{$filename}");

            // Ensure directory exists
            if (!file_exists(dirname($fullPath))) {
                mkdir(dirname($fullPath), 0755, true);
            }

            Pdf::view('pdf.e-ticket', [
                'booking' => $booking,
                'tenant' => $booking->tenant,
                'customer' => $booking->customer,
            ])
            ->format('a4')
            ->save($fullPath);

            return $fullPath;

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('PDF Generation Failed during Notification: ' . $e->getMessage());
            return null;
        }
    }
}
