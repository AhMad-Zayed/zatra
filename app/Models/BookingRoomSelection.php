<?php

namespace App\Models;

use App\Enums\OccupancyTypeEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Booking-time room-type selection (quantity + occupancy) — analogous to BookingAddon, a
 * snapshot of a catalog choice. No per-passenger assignment (Ticket 3). Hotel/Rooming redesign
 * Ticket 2.
 */
class BookingRoomSelection extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'booking_id',
        'room_type_id',
        'quantity',
        'occupancy_type',
        'price_at_booking',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'occupancy_type' => OccupancyTypeEnum::class,
        'price_at_booking' => \App\Casts\MoneyCast::class,
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }
}
