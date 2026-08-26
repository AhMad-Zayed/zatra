<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Enums\TripStatusEnum;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class TripInstance extends Model implements HasMedia
{
    use HasFactory, SoftDeletes, InteractsWithMedia;

    protected $fillable = [
        'uuid',
        'tenant_id',
        'trip_template_id',
        'start_date',
        'currency',
        'end_date',
        'available_seats',
        'status',
        'price_override',
        'price_override_amount',
    ];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('cover')->singleFile();
    }

    public function getEffectiveCoverUrlAttribute(): ?string
    {
        if ($this->hasMedia('cover')) {
            return $this->getFirstMediaUrl('cover');
        }
        if ($this->tripTemplate && $this->tripTemplate->hasMedia('cover')) {
            return $this->tripTemplate->getFirstMediaUrl('cover');
        }
        return null;
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) \Illuminate\Support\Str::uuid();
            }
        });

        static::updating(function ($model) {
            if ($model->isDirty('currency')) {
                if ($model->bookings()->exists()) {
                    throw new \RuntimeException("Currency cannot be changed after bookings have been made.");
                }
            }
        });
    }

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'available_seats' => 'integer',
        'status' => TripStatusEnum::class,
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
        'price_override_amount' => \App\Casts\MoneyCast::class,
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function tripTemplate(): BelongsTo
    {
        return $this->belongsTo(TripTemplate::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function passengers(): \Illuminate\Database\Eloquent\Relations\HasManyThrough
    {
        return $this->hasManyThrough(Passenger::class, Booking::class);
    }

    public function activePassengers(): \Illuminate\Database\Eloquent\Relations\HasManyThrough
    {
        return $this->hasManyThrough(Passenger::class, Booking::class)
                    ->whereNotIn('bookings.booking_status', ['cancelled', 'failed']);
    }

    public function packageOptions(): HasMany
    {
        return $this->hasMany(PackageOption::class)->orderBy('sort_order');
    }

    // Hotel/Rooming redesign Phase 1 — built alongside packageOptions() above, not replacing
    // it yet. See docs discussion: PackageOption stays fully live until Ticket 2/3 migrate its
    // call sites over.
    public function tripStayLegs(): HasMany
    {
        return $this->hasMany(TripStayLeg::class)->orderBy('sequence');
    }

    // Bus/Fleet redesign Ticket 1 — additive, alongside the columns/relations above. Multiple
    // rows here per trip is what supports assigning more than one bus to the same trip.
    public function tripBusAssignments(): HasMany
    {
        return $this->hasMany(TripBusAssignment::class)->orderBy('sort_order');
    }

    /**
     * True only if the trip's accommodation catalog actually has at least one active room type
     * AND the tenant-level kill switch (tenants.settings['room_booking_enabled']) is on.
     * Belt-and-suspenders single source of truth for whether room-selection UI/payloads should
     * be honored anywhere — both the "does data exist" check and the "is the feature enabled"
     * check are combined here so no caller can accidentally check only one of the two.
     * Defaults to false (opt-in per tenant), per the Ticket 2 rollout decision.
     */
    public function getRoomBookingIsAvailableAttribute(): bool
    {
        $enabledForTenant = (bool) ($this->tenant?->settings['room_booking_enabled'] ?? false);

        if (!$enabledForTenant) {
            return false;
        }

        return $this->tripStayLegs()
            ->whereHas('hotelOptions', function ($q) {
                $q->where('is_active', true)->whereHas('roomTypes', fn ($q2) => $q2->where('is_active', true));
            })
            ->exists();
    }

    public function activePackageOptions(): HasMany
    {
        return $this->hasMany(PackageOption::class)
                    ->where('is_active', true)
                    ->orderBy('sort_order');
    }

    public function waitingLists()
    {
        return $this->belongsToMany(WaitingList::class, 'trip_instance_waiting_list')
                    ->withTimestamps();
    }

    public function pickupRoutes()
    {
        return $this->belongsToMany(PickupRoute::class, 'trip_instance_pickup_routes');
    }

    public function tripPassengerCategories(): HasMany
    {
        return $this->hasMany(TripPassengerCategory::class);
    }

    public function tripAddons(): HasMany
    {
        return $this->hasMany(TripAddon::class);
    }

    public function getRemainingSeatsAttribute(): int
    {
        if ($this->available_seats === null) {
            return PHP_INT_MAX;
        }

        $taken = \App\Models\InventoryLedger::where('trip_instance_id', $this->id)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            })
            ->sum('quantity');

        return max(0, $this->available_seats + $taken);
    }

    public function getPassengerWithAddons()
    {
        return \App\Models\Passenger::whereHas('booking', function ($q) {
            $q->where('trip_instance_id', $this->id)
              ->whereIn('booking_status', [\App\Enums\BookingStatus::Confirmed, \App\Enums\BookingStatus::Pending]);
        })->with('bookingAddons.tripAddon')->get()->groupBy('id');
    }

    public function scopeBookable($query)
    {
        return $query->where('status', \App\Enums\TripStatusEnum::Active)
                     ->where('start_date', '>=', now()->startOfDay());
    }
}
