<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Tenant;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use App\Exceptions\Auth\OtpCoolDownException;
use App\Exceptions\Auth\InvalidOtpException;
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

        // Dispatch appropriate Notification
        if (app()->environment('production', 'staging')) {
            // TODO: Implement a proper SendCustomerNotificationJob for OTPs
            // \App\Jobs\SendCustomerNotificationJob::dispatch($customer, $isEmail ? 'email' : 'whatsapp', "Your authentication code is {$otp}");
            \Illuminate\Support\Facades\Log::info("Production OTP for {$field} {$cleanIdentifier}: {$otp}");
        } else {
            \Illuminate\Support\Facades\Log::info("Local/Testing OTP for {$field} {$cleanIdentifier}: {$otp}");
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
