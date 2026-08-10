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

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }
}
