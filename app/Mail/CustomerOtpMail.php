<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A genuine Mailable (rather than Mail::raw()) specifically so this is trackable by Mail::fake()
 * in tests -- Illuminate\Support\Testing\Fakes\MailFake::raw() is a hard no-op that records
 * nothing at all, which would make "prove sendOtp() actually attempts a real send" untestable.
 */
class CustomerOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $otp)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'رمز تسجيل الدخول');
    }

    public function content(): Content
    {
        return new Content(
            htmlString: "<p>رمز الدخول الخاص بك هو: <strong>{$this->otp}</strong></p><p>هذا الرمز صالح لمدة 10 دقائق.</p>",
        );
    }
}
