<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Reusable master entity, deliberately not tied to any single trip — referenced (never copied)
 * by TripStayLegHotelOption. Hotel/Rooming redesign Phase 1.
 */
class Hotel extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'name',
        'city',
        'star_rating',
        'contact_phone',
        'contact_email',
        'address',
        'is_active',
    ];

    protected $casts = [
        'star_rating' => 'integer',
        'is_active' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function tripStayLegHotelOptions(): HasMany
    {
        return $this->hasMany(TripStayLegHotelOption::class);
    }
}
