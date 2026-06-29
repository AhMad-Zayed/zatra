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
    ];

    protected $casts = [
        'price_at_booking' => \App\Casts\MoneyCast::class,
        'date_of_birth' => 'date',
        'extra_preferences' => 'array',
    ];

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
}
