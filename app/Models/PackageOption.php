<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
class PackageOption extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'trip_instance_id', 'name', 'hotel_name', 'stars',
        'room_type', 'meal_plan', 'price_adjustment', 'available_seats',
        'description', 'included_features', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'price_adjustment'  => \App\Casts\MoneyCast::class,
        'included_features' => 'array',
        'is_active'         => 'boolean',
    ];

    public function tripInstance(): BelongsTo
    {
        return $this->belongsTo(TripInstance::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    // How many seats remain for THIS specific package
    // If available_seats is null, delegate to TripInstance remaining_seats
    public function getRemainingSeatsAttribute(): int
    {
        if ($this->available_seats === null) {
            return $this->tripInstance->remaining_seats;
        }

        $booked = \App\Models\Passenger::whereIn('booking_id', function($q) {
            $q->select('id')->from('bookings')
              ->where('package_option_id', $this->id)
              ->whereNotIn('booking_status', ['cancelled', 'failed']);
        })->whereHas('tripPassengerCategory', function($q) {
            $q->where('requires_seat', true);
        })->count();

        $packageRemaining = max(0, $this->available_seats - $booked);
        
        // CRITICAL: A package cannot offer more seats than the bus itself has available.
        return min($packageRemaining, $this->tripInstance->remaining_seats);
    }

    // Helper: render stars as string for display
    public function getStarsDisplayAttribute(): string
    {
        return $this->stars ? str_repeat('★', $this->stars) : '';
    }
}
