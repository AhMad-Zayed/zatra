<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Tenant extends Model implements HasMedia
{
    use HasFactory, SoftDeletes, InteractsWithMedia;

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('logo')->singleFile();
        $this->addMediaCollection('hero_image')->singleFile();
    }

    protected $fillable = [
        'name',
        'slug',
        'domain',
        'is_active',
        'is_visa_enabled',
        'payment_gateway_provider',
        'enable_email_alerts',
        'enable_whatsapp_alerts',
        'enable_sms_alerts',
        'tourism_license_number',
        'terms_conditions',
        'privacy_policy',
        'refund_policy',
        'settings',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'cash_booking_expiry_hours' => 'integer',
        'enable_email_alerts' => 'boolean',
        'enable_whatsapp_alerts' => 'boolean',
        'enable_sms_alerts' => 'boolean',
        'settings' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function tripTemplates(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(TripTemplate::class);
    }

    public function tripInstances(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(TripInstance::class);
    }

    public function bookings(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function passengers(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Passenger::class);
    }

    public function payments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function templatePassengerCategories(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(TemplatePassengerCategory::class);
    }

    public function templateAddons(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(TemplateAddon::class);
    }

    public function passengerCategorys(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PassengerCategory::class);
    }

    public function globalAddons(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(GlobalAddon::class);
    }

    public function pickupRoutes(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PickupRoute::class);
    }

    public function requirementPresets(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(RequirementPreset::class);
    }

    // Hotel/Rooming redesign Phase 1 — required for Filament's native panel tenancy to
    // auto-assign tenant_id on create for HotelResource, matching every other top-level
    // tenant-scoped resource's identical relation here.
    public function hotels(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Hotel::class);
    }
}
