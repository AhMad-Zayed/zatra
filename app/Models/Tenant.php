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
        'is_visa_enabled',
    ];

    protected $casts = [
        'is_visa_enabled' => 'boolean',
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

    public function templatePricingTiers(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(TemplatePricingTier::class);
    }

    public function templateAddons(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(TemplateAddon::class);
    }

    public function globalPricingTiers(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(GlobalPricingTier::class);
    }

    public function globalAddons(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(GlobalAddon::class);
    }
}
