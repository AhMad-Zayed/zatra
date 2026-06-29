<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryLedger extends Model
{
    use HasFactory;

    protected $fillable = [
        'trip_instance_id',
        'booking_id',
        'quantity',
        'type',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'quantity' => 'integer',
    ];

    public function tripInstance(): BelongsTo
    {
        return $this->belongsTo(TripInstance::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
