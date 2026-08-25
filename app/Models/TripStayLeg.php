<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An ordered stage of a trip instance's accommodation (leg 1, leg 2, ...). A simple trip has
 * exactly one leg. Hotel/Rooming redesign Phase 1.
 */
class TripStayLeg extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'trip_instance_id',
        'sequence',
        'label',
        'start_date',
        'end_date',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function tripInstance(): BelongsTo
    {
        return $this->belongsTo(TripInstance::class);
    }

    public function hotelOptions(): HasMany
    {
        return $this->hasMany(TripStayLegHotelOption::class)->orderBy('sort_order');
    }
}
