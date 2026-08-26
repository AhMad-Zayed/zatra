<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which specific passenger occupies which specific room_instance. passenger_id is unique — a
 * passenger occupies exactly one room; moving them updates this row rather than adding a second
 * one. Hotel/Rooming redesign Ticket 3.
 */
class RoomAssignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'room_instance_id',
        'passenger_id',
        'booking_id',
        'assigned_by',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function roomInstance(): BelongsTo
    {
        return $this->belongsTo(RoomInstance::class);
    }

    public function passenger(): BelongsTo
    {
        return $this->belongsTo(Passenger::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
