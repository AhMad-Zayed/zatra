<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;

class CustomerAuthService
{
    public function findOrCreateByPhone(string $phone, ?string $name = null, ?int $tenantId = null): User
    {
        $normalizedPhone = $this->normalizePhone($phone);

        // Find or create global user by phone
        $user = User::firstOrCreate(
            ['phone' => $normalizedPhone],
            [
                'name' => $name ?? 'Customer ' . substr($normalizedPhone, -4),
                'password' => null, // customers never have passwords
            ]
        );

        // Resolve tenant
        $tenantId ??= Filament::getTenant()?->id;

        if ($tenantId) {
            // Link user to the tenant via pivot table
            $user->tenants()->syncWithoutDetaching([$tenantId]);
        }

        return $user;
    }

    public function normalizePhone(string $phone): string
    {
        // Strip spaces, dashes, parentheses
        $cleaned = preg_replace('/[^0-9+]/', '', $phone);

        // If it starts with 00, replace with +
        if (str_starts_with($cleaned, '00')) {
            $cleaned = '+' . substr($cleaned, 2);
        }

        return $cleaned;
    }

    public function sendOtp(string $phone): void
    {
        $normalizedPhone = $this->normalizePhone($phone);
        
        // Generate random 4-digit code
        $otp = (string) mt_rand(1000, 9999);
        
        // Store in Cache for 10 minutes
        \Illuminate\Support\Facades\Cache::put("otp:{$normalizedPhone}", $otp, now()->addMinutes(10));
        
        // Send notification using custom channel
        \Illuminate\Support\Facades\Notification::route(\App\Channels\WhatsAppChannel::class, $normalizedPhone)
            ->notify(new \App\Notifications\CustomerOtpNotification($otp));
    }

    public function verifyOtp(string $phone, string $otp): bool
    {
        $normalizedPhone = $this->normalizePhone($phone);
        $cachedOtp = \Illuminate\Support\Facades\Cache::get("otp:{$normalizedPhone}");
        
        if ($cachedOtp && $cachedOtp === $otp) {
            \Illuminate\Support\Facades\Cache::forget("otp:{$normalizedPhone}");
            return true;
        }

        // Allow static code '1234' for local manual testing & tests
        if (app()->environment('testing', 'local') && $otp === '1234') {
            return true;
        }
        
        return false;
    }
}
