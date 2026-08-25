<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The alternative hotel choice(s) available within a given leg (a leg can have 1 or several
 * simultaneously, e.g. different star-rating options for the same dates). Hotel/Rooming
 * redesign Phase 1.
 */
class TripStayLegHotelOption extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'trip_stay_leg_id',
        'hotel_id',
        'label',
        'meal_plan',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function tripStayLeg(): BelongsTo
    {
        return $this->belongsTo(TripStayLeg::class);
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    public function roomTypes(): HasMany
    {
        return $this->hasMany(RoomType::class)->orderBy('sort_order');
    }
}
