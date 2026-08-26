<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Reusable master entity for a COMPANY-OWNED bus only — the same physical vehicle, reused
 * across many trips over time (mirrors Hotel's role in the Hotel/Rooming redesign). Rented
 * buses are never stored here; see TripBusAssignment's migration docblock. Bus/Fleet redesign
 * Ticket 1.
 */
class Vehicle extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'plate_number',
        'default_capacity',
        'default_driver_id',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'default_capacity' => 'integer',
        'is_active' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function defaultDriver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'default_driver_id');
    }

    public function tripBusAssignments(): HasMany
    {
        return $this->hasMany(TripBusAssignment::class);
    }
}
