<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tenant extends Model
{
    use HasFactory, SoftDeletes;

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
}
