<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Tenant;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use App\Exceptions\Auth\OtpCoolDownException;
use App\Exceptions\Auth\InvalidOtpException;
use App\Exceptions\Auth\OtpDeliveryException;
use Exception;

class CustomerOtpService
{
    /**
     * Sanitize phone number (strip spaces, dashes, formatting).
     */
    private function sanitizePhone(string $phone): string
    {
        return preg_replace('/[^0-9+]/', '', $phone);
    }

    /**
     * Send an OTP to a customer (Email or Phone).
     */
    public function sendOtp(Tenant $tenant, string $identifier): void
    {
        $isEmail = filter_var($identifier, FILTER_VALIDATE_EMAIL);
        $cleanIdentifier = $isEmail ? strtolower(trim($identifier)) : $this->sanitizePhone($identifier);

        $verificationKey = "otp_verification:{$tenant->id}:{$cleanIdentifier}";
        $generationKey = "otp_generation:{$tenant->id}:{$cleanIdentifier}";

        if (RateLimiter::tooManyAttempts($verificationKey, 5)) {
            $secondsRemaining = RateLimiter::availableIn($verificationKey);
            throw new OtpCoolDownException($secondsRemaining, "Account in cool-down due to too many failed attempts.");
        }

        if (RateLimiter::tooManyAttempts($generationKey, 3)) {
            $secondsRemaining = RateLimiter::availableIn($generationKey);
            throw new OtpCoolDownException($secondsRemaining, "Too many OTP requests. Please wait.");
        }

        RateLimiter::hit($generationKey, 15 * 60); // 15 mins decay

        $otp = (string) random_int(100000, 999999);

        // Find or Create Customer
        $field = $isEmail ? 'email' : 'phone';
        $customer = Customer::firstOrCreate(
            ['tenant_id' => $tenant->id, $field => $cleanIdentifier]
        );

        $customer->update([
            'otp_code' => Hash::make($otp),
            'otp_expires_at' => now()->addMinutes(10),
        ]);

        // Emergency hotfix: this used to only ever log the code, in every environment,
        // including production -- no customer could ever actually receive a real OTP, so login
        // silently appeared to work while being completely broken. Local/testing keeps the
        // log-only behavior (no real WhatsApp Business/mail credentials are expected on a dev
        // machine, and CustomerLogin's own "1234" bypass in local/testing doesn't depend on this
        // anyway); production/staging now genuinely attempts delivery and fails loudly --
        // throwing, not silently continuing -- if it can't, exactly like the earlier Browsershot
        // fix this session for missing PDF-generation binaries.
        if (app()->environment('production', 'staging')) {
            $this->deliver($isEmail, $cleanIdentifier, $otp);
        } else {
            Log::info("Local/Testing OTP for {$field} {$cleanIdentifier}: {$otp}");
        }
    }

    /**
     * Actually attempt delivery of the OTP code, via whichever channel already exists and is
     * integrated in this codebase for the identifier type -- email via the same Mail facade
     * EmailNotificationDriver already uses, WhatsApp via the same Meta Graph API call and
     * services.whatsapp config keys WhatsAppNotificationDriver already uses for booking
     * notifications. Not routed through NotificationDriverInterface/NotificationManager: both
     * existing drivers are typed to take a Booking (and WhatsAppNotificationDriver hardcodes the
     * booking_confirmation_v1 template) -- neither fits a pre-booking login OTP, which needs its
     * own approved WhatsApp template. Same provider and credentials, different message.
     *
     * @throws OtpDeliveryException
     */
    private function deliver(bool $isEmail, string $identifier, string $otp): void
    {
        if ($isEmail) {
            $this->deliverByEmail($identifier, $otp);
            return;
        }

        $this->deliverByWhatsApp($identifier, $otp);
    }

    private function deliverByEmail(string $email, string $otp): void
    {
        try {
            Mail::to($email)->send(new \App\Mail\CustomerOtpMail($otp));
        } catch (Exception $e) {
            Log::error("OTP email delivery failed for {$email}: " . $e->getMessage());
            throw new OtpDeliveryException("تعذر إرسال رمز التحقق عبر البريد الإلكتروني.", previous: $e);
        }
    }

    private function deliverByWhatsApp(string $phone, string $otp): void
    {
        $token = config('services.whatsapp.token');
        $phoneId = config('services.whatsapp.phone_id');
        $template = config('services.whatsapp.otp_template');

        if (!$token || !$phoneId) {
            Log::error("OTP WhatsApp delivery failed for {$phone}: WHATSAPP_TOKEN/WHATSAPP_PHONE_ID is not configured on this server.");
            throw new OtpDeliveryException('تعذر إرسال رمز التحقق عبر واتساب. يرجى المحاولة لاحقاً أو التواصل مع الدعم.');
        }

        $formattedPhone = preg_replace('/[^0-9]/', '', $phone);
        $endpoint = "https://graph.facebook.com/v17.0/{$phoneId}/messages";

        try {
            $response = Http::withToken($token)
                ->timeout(10)
                ->retry(3, 1000)
                ->post($endpoint, [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $formattedPhone,
                    'type' => 'template',
                    'template' => [
                        // Must match a real, Meta-approved "Authentication" category template
                        // on the tenant's WhatsApp Business account (services.whatsapp.otp_template
                        // / WHATSAPP_OTP_TEMPLATE). The default value is a placeholder name, not
                        // a template that exists in Meta's system yet -- this call will fail
                        // (loudly, below) until the stakeholder creates and gets that template
                        // approved and points this config at its real name.
                        'name' => $template,
                        'language' => ['code' => 'ar'],
                        'components' => [
                            [
                                'type' => 'body',
                                'parameters' => [
                                    ['type' => 'text', 'text' => $otp],
                                ],
                            ],
                        ],
                    ],
                ]);
        } catch (Exception $e) {
            Log::error("OTP WhatsApp delivery failed for {$phone}: " . $e->getMessage());
            throw new OtpDeliveryException('تعذر إرسال رمز التحقق عبر واتساب. يرجى المحاولة لاحقاً أو التواصل مع الدعم.', previous: $e);
        }

        if ($response->failed()) {
            $errorMessage = $response->json('error.message', $response->body());
            Log::error("OTP WhatsApp delivery failed for {$phone}: {$errorMessage}");
            throw new OtpDeliveryException('تعذر إرسال رمز التحقق عبر واتساب. يرجى المحاولة لاحقاً أو التواصل مع الدعم.');
        }
    }

    /**
     * Verify an OTP.
     */
    public function verifyOtp(Tenant $tenant, string $identifier, string $otpInput): Customer
    {
        $isEmail = filter_var($identifier, FILTER_VALIDATE_EMAIL);
        $cleanIdentifier = $isEmail ? strtolower(trim($identifier)) : $this->sanitizePhone($identifier);

        $verificationKey = "otp_verification:{$tenant->id}:{$cleanIdentifier}";

        if (RateLimiter::tooManyAttempts($verificationKey, 5)) {
            $secondsRemaining = RateLimiter::availableIn($verificationKey);
            throw new OtpCoolDownException($secondsRemaining, "Account locked. Try again later.");
        }

        $field = $isEmail ? 'email' : 'phone';
        $customer = Customer::where('tenant_id', $tenant->id)->where($field, $cleanIdentifier)->first();

        if (!$customer || !$customer->otp_code || !$customer->otp_expires_at) {
            RateLimiter::hit($verificationKey, 15 * 60);
            throw new InvalidOtpException("Invalid or expired OTP.");
        }

        if (now()->isAfter($customer->otp_expires_at)) {
            RateLimiter::hit($verificationKey, 15 * 60);
            throw new InvalidOtpException("OTP has expired.");
        }

        if (!Hash::check($otpInput, $customer->otp_code)) {
            RateLimiter::hit($verificationKey, 15 * 60);
            throw new InvalidOtpException("Invalid OTP.");
        }

        RateLimiter::clear($verificationKey);
        
        $customer->update([
            'otp_code' => null,
            'otp_expires_at' => null,
        ]);

        return $customer;
    }
}
