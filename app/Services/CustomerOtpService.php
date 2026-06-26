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
     * Send an OTP to a customer.
     * Includes 15-minute cool-down check and generation rate limiting.
     */
    public function sendOtp(Tenant $tenant, string $phone): void
    {
        $verificationKey = "otp_verification:{$tenant->id}:{$phone}";
        $generationKey = "otp_generation:{$tenant->id}:{$phone}";

        // 1. Check if the user is in a 15-minute cool-down from failing verification
        if (RateLimiter::tooManyAttempts($verificationKey, 5)) {
            $secondsRemaining = RateLimiter::availableIn($verificationKey);
            throw new OtpCoolDownException($secondsRemaining, "Account in cool-down due to too many failed attempts.");
        }

        // 2. Check Generation Rate Limit (max 3 OTPs generated per 15 mins)
        if (RateLimiter::tooManyAttempts($generationKey, 3)) {
            $secondsRemaining = RateLimiter::availableIn($generationKey);
            throw new OtpCoolDownException($secondsRemaining, "Too many OTP requests. Please wait.");
        }

        RateLimiter::hit($generationKey, 15 * 60); // 15 mins decay

        // 3. Generate Cryptographically Secure OTP
        $otp = (string) random_int(100000, 999999);

        // 4. Find or Create Customer
        $customer = Customer::firstOrCreate(
            ['tenant_id' => $tenant->id, 'phone' => $phone]
        );

        // 5. Hash and Store OTP
        $customer->update([
            'otp_code' => Hash::make($otp),
            'otp_expires_at' => now()->addMinutes(10),
        ]);

        // 6. TODO: Dispatch SMS/WhatsApp Job
        // \Illuminate\Support\Facades\Log::info("OTP for {$phone} is {$otp}");
    }

    /**
     * Verify an OTP.
     * Enforces strict rate limiting and prevents replay attacks.
     */
    public function verifyOtp(Tenant $tenant, string $phone, string $otpInput): Customer
    {
        $verificationKey = "otp_verification:{$tenant->id}:{$phone}";

        // 1. Check Cool-down
        if (RateLimiter::tooManyAttempts($verificationKey, 5)) {
            $secondsRemaining = RateLimiter::availableIn($verificationKey);
            throw new OtpCoolDownException($secondsRemaining, "Account locked. Try again later.");
        }

        $customer = Customer::where('tenant_id', $tenant->id)->where('phone', $phone)->first();

        if (!$customer || !$customer->otp_code || !$customer->otp_expires_at) {
            RateLimiter::hit($verificationKey, 15 * 60); // 15 mins
            throw new InvalidOtpException("Invalid or expired OTP.");
        }

        // 2. Check Expiration
        if (now()->isAfter($customer->otp_expires_at)) {
            RateLimiter::hit($verificationKey, 15 * 60);
            throw new InvalidOtpException("OTP has expired.");
        }

        // 3. Secure Hash Comparison
        if (!Hash::check($otpInput, $customer->otp_code)) {
            RateLimiter::hit($verificationKey, 15 * 60);
            throw new InvalidOtpException("Invalid OTP.");
        }

        // 4. SUCCESS: Clear Verification Attempts & Prevent Replay Attack
        RateLimiter::clear($verificationKey);
        
        $customer->update([
            'otp_code' => null,
            'otp_expires_at' => null,
        ]);

        return $customer;
    }
}
