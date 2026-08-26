<?php

namespace App\Models;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Booking extends Model implements HasMedia
{
    use HasFactory, SoftDeletes, InteractsWithMedia;

    protected $fillable = [
        'tenant_id',
        'trip_instance_id',
        'package_option_id',
        'package_price_at_booking',
        'customer_id',
        'user_id',
        'pnr',
        'idempotency_key',
        'currency',
        'uuid',
        'booking_status',
        'payment_status',
        'grand_total',
        'total_paid',
        'deposit_amount',
        'payment_type',
        'notes',
        'expires_at',
        'snapshot_trip_title',
        'snapshot_template_name',
        'snapshot_start_date',
        'snapshot_end_date',
        'snapshot_currency',
        'snapshot_total_price',
        'snapshot_taxes',
        'snapshot_discounts',
        'snapshot_passenger_rules',
        'discount_amount',
        'balance_due',
        'cancelled_reason',
        'cancellation_requested_at',
        'review_requested',
    ];

    protected $casts = [
        'booking_status' => BookingStatus::class,
        'payment_status' => PaymentStatus::class,
        'grand_total' => \App\Casts\MoneyCast::class,
        'package_price_at_booking' => \App\Casts\MoneyCast::class,
        'discount_amount' => \App\Casts\MoneyCast::class,
        'total_paid' => \App\Casts\MoneyCast::class,
        'balance_due' => \App\Casts\MoneyCast::class,
        'deposit_amount' => \App\Casts\MoneyCast::class,
        'expires_at' => 'datetime',
        'snapshot_start_date' => 'date',
        'snapshot_end_date' => 'date',
        'snapshot_passenger_rules' => 'array',
    ];

    public function getBalanceDueAttribute(): float|int
    {
        return $this->grand_total - ($this->total_paid ?? 0);
    }

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

            // Price Integrity Audit, Finding A: captures the PackageOption's per-passenger
            // price_adjustment at the exact moment a booking is created, so
            // BookingService::recalculateTotals() (guardrail-protected, one line changed) can
            // read this frozen value instead of re-reading the package's CURRENT live price on
            // every later recalculation. Model-level hook rather than a CreateBookingService
            // edit -- CreateBookingService::execute()'s Booking::create() call runs synchronously
            // inside the same transaction as the real booking flow, so "the moment this hook
            // fires" and "the moment of booking" are the same instant for a genuinely new
            // booking. Guarded so an explicit value (e.g. the backfill migration, or a future
            // caller with its own reason) is never silently overwritten.
            // Checked against the RAW attribute, not the MoneyCast accessor -- MoneyCast::get()
            // returns 0.00 (never actual null) for an unset attribute, so is_null() on the cast
            // value would never be true and this guard would silently never fire.
            if ($model->package_option_id && !array_key_exists('package_price_at_booking', $model->getAttributes())) {
                $model->package_price_at_booking = \App\Models\PackageOption::find($model->package_option_id)?->price_adjustment ?? 0;
            }
        });

        static::updating(function ($model) {
            $snapshotFields = [
                'snapshot_trip_title',
                'snapshot_template_name',
                'snapshot_start_date',
                'snapshot_end_date',
                'snapshot_currency',
                'snapshot_total_price',
                'snapshot_taxes',
                'snapshot_discounts',
                'snapshot_passenger_rules',
                'package_price_at_booking',
            ];

            foreach ($snapshotFields as $field) {
                if ($model->isDirty($field) && $model->getOriginal($field) !== null) {
                    throw new \Exception("Cannot modify immutable snapshot field: {$field}");
                }
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

    public function packageOption(): BelongsTo
    {
        return $this->belongsTo(PackageOption::class);
    }

    // Hotel/Rooming redesign Ticket 2 — built alongside packageOption() above, not replacing
    // it. A booking uses one system or the other in practice (whichever the trip instance has
    // configured), never both; nothing here enforces that exclusivity at the DB level.
    public function roomSelections(): HasMany
    {
        return $this->hasMany(BookingRoomSelection::class);
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

    public function bookingPickups(): HasMany
    {
        return $this->hasMany(BookingPickup::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
