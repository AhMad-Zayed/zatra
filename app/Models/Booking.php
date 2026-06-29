<?php

namespace App\Models;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Booking extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'trip_instance_id',
        'customer_id',
        'user_id',
        'pnr',
        'uuid',
        'booking_status',
        'payment_status',
        'grand_total',
        'total_paid',
        'balance_due',
        'notes',
        'expires_at',
    ];

    protected $casts = [
        'booking_status' => BookingStatus::class,
        'payment_status' => PaymentStatus::class,
        'grand_total' => \App\Casts\MoneyCast::class,
        'total_paid' => \App\Casts\MoneyCast::class,
        'balance_due' => \App\Casts\MoneyCast::class,
        'expires_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (auth()->check() && !\Filament\Facades\Filament::getTenant()) {
                // Keep this as fallback if needed for admins
            } else {
                $model->tenant_id ??= \Filament\Facades\Filament::getTenant()?->id;
            }

            // Auto-generate UUID
            if (empty($model->uuid)) {
                $model->uuid = (string) \Illuminate\Support\Str::uuid();
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function tripInstance(): BelongsTo
    {
        return $this->belongsTo(TripInstance::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function passengers(): HasMany
    {
        return $this->hasMany(Passenger::class);
    }

    public function bookingAddons(): HasMany
    {
        return $this->hasMany(BookingAddon::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
