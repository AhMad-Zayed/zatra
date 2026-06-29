<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Enums\TripStatusEnum;
use App\Traits\HasTripState;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class TripInstance extends Model implements HasMedia
{
    use HasFactory, SoftDeletes, HasTripState, InteractsWithMedia;

    protected $fillable = [
        'tenant_id',
        'trip_template_id',
        'start_date',
        'end_date',
        'available_seats',
        'status',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'available_seats' => 'integer',
        'status' => TripStatusEnum::class,
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
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

    public function waitingLists(): HasMany
    {
        return $this->hasMany(WaitingList::class);
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

        $available = \App\Models\InventoryLedger::where('trip_instance_id', $this->id)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            })
            ->sum('quantity');

        return max(0, $available);
    }

    public function getPassengerWithAddons()
    {
        return \App\Models\Passenger::whereHas('booking', function ($q) {
            $q->where('trip_instance_id', $this->id)
              ->whereIn('booking_status', [\App\Enums\BookingStatus::Confirmed, \App\Enums\BookingStatus::Pending]);
        })->with('bookingAddons.tripAddon')->get()->groupBy('id');
    }
}
