<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A room category (single/double/triple/quad) within a specific hotel option, with a real
 * physical room-count inventory (not a person-count cap). No inventory ledger yet (Ticket 2)
 * and no RoomAssignment yet (Ticket 3) — this model only defines what a room type is.
 * price_adjustment_shared/price_adjustment_single_supplement carry no independent currency;
 * both are always denominated in the owning trip instance's currency (reached via
 * tripStayLegHotelOption -> tripStayLeg -> tripInstance), matching the currency-inheritance
 * principle already enforced for Payment/Booking/PackageOption elsewhere in this app.
 * Hotel/Rooming redesign Phase 1.
 */
class RoomType extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'trip_stay_leg_hotel_option_id',
        'name',
        'capacity_per_room',
        'room_count',
        'price_adjustment_shared',
        'price_adjustment_single_supplement',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'capacity_per_room' => 'integer',
        'room_count' => 'integer',
        'price_adjustment_shared' => \App\Casts\MoneyCast::class,
        'price_adjustment_single_supplement' => \App\Casts\MoneyCast::class,
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function tripStayLegHotelOption(): BelongsTo
    {
        return $this->belongsTo(TripStayLegHotelOption::class);
    }

    // Ticket 3 additions — purely new accessors, no change to what this model already did.
    public function roomInstances(): HasMany
    {
        return $this->hasMany(RoomInstance::class)->orderBy('room_number');
    }

    public function bookingRoomSelections(): HasMany
    {
        return $this->hasMany(BookingRoomSelection::class);
    }
}
