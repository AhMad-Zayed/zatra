<?php

namespace App\Mail;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BookingConfirmedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Booking $booking,
        public string $messageText,
        public array $attachmentPaths = []
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'تأكيد حجزك - ' . ($this->booking->tenant->name ?? 'Zatara'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.booking-confirmed',
            with: [
                'booking' => $this->booking,
                'messageText' => $this->messageText,
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        $mailAttachments = [];

        foreach ($this->attachmentPaths as $path) {
            if (file_exists($path)) {
                $mailAttachments[] = Attachment::fromPath($path)
                    ->as('Zatara-Ticket-' . $this->booking->id . '.pdf')
                    ->withMime('application/pdf');
            }
        }

        return $mailAttachments;
    }
}
