<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Customer extends Authenticatable
{
    use Notifiable;

    protected $fillable = [
        'tenant_id',
        'name',
        'phone',
        'email',
        'provider_id',
        'provider_name',
        'otp_code',
        'otp_expires_at',
        'notes',
    ];

    protected $casts = [
        'otp_expires_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Case-Insensitive Uniqueness Fix: normalize email to lowercase at the single point
        // every write path (create AND update) funnels through, matching the existing
        // TripTemplate::creating/updating idiom already used elsewhere in this codebase for
        // exactly this "normalize a field before it's stored" shape of problem. This alone does
        // NOT cover firstOrCreate()'s search array (mutators/hooks never touch that -- only the
        // eventual create() call) -- callers that search by email must still lowercase their own
        // input before querying; see SocialAuthController and GuestSession (which normalizes at
        // its own source so CreateBookingService's guest-session lookup doesn't need touching).
        static::saving(function ($customer) {
            if (!empty($customer->email)) {
                $customer->email = \Illuminate\Support\Str::lower(trim($customer->email));
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }
}
