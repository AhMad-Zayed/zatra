<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Passenger extends Model implements HasMedia
{
    use HasFactory, SoftDeletes, InteractsWithMedia;

    protected $fillable = [
        'tenant_id',
        'booking_id',
        'trip_passenger_category_id',
        'price_at_booking',
        'first_name',
        'last_name',
        'document_type',
        'document_number',
        'date_of_birth',
        'gender',
        'extra_preferences',
        // Phone booking mode: seat reserved, data collected later via self-service link
        'data_complete',
        // Distinct from data_complete: whether this passenger satisfies the trip's attached
        // RequirementPreset (text/date/image items), not just whether a name was given.
        'requirements_complete',
        'passenger_label',
        'seat_number',
        'trip_bus_assignment_id',
        'is_checked_in',
    ];

    protected $casts = [
        'price_at_booking' => \App\Casts\MoneyCast::class,
        'date_of_birth' => 'date',
        'extra_preferences' => 'array',
        'data_complete' => 'boolean',
        'requirements_complete' => 'boolean',
        'is_checked_in' => 'boolean',
    ];

    /**
     * Display name: shows actual name if known, otherwise the placeholder label.
     */
    public function getDisplayNameAttribute(): string
    {
        if (!empty($this->first_name)) {
            return trim($this->first_name . ' ' . $this->last_name);
        }
        return $this->passenger_label ?? 'راكب غير مُعرَّف';
    }

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (auth()->check()) {
                $model->tenant_id ??= \Filament\Facades\Filament::getTenant()?->id;
            }
        });
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('identity_documents')
             ->useDisk('private')
             ->acceptsMimeTypes(['image/jpeg', 'image/png', 'application/pdf']);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function tripPassengerCategory(): BelongsTo
    {
        return $this->belongsTo(TripPassengerCategory::class, 'trip_passenger_category_id');
    }

    // Hotel/Rooming redesign Ticket 3 — purely additive accessor, no change to existing behavior.
    public function roomAssignment(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(RoomAssignment::class);
    }

    // Bus/Fleet redesign Ticket 3 — purely additive, alongside the existing seat_number column.
    public function tripBusAssignment(): BelongsTo
    {
        return $this->belongsTo(TripBusAssignment::class);
    }
}
