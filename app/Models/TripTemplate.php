<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class TripTemplate extends Model implements HasMedia
{
    use HasFactory, SoftDeletes, InteractsWithMedia;

    protected $fillable = [
        'tenant_id',
        'title',
        'description',
        'base_price',
        'passenger_requirements',
        'requirement_preset_id',
        'deposit_percentage',
        'deposit_enabled',
    ];

    protected $casts = [
        'base_price' => \App\Casts\MoneyCast::class,
        'passenger_requirements' => 'array',
        'deposit_enabled' => 'boolean',
        'deposit_percentage' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function tripInstances(): HasMany
    {
        return $this->hasMany(TripInstance::class);
    }

    public function templatePassengerCategories(): HasMany
    {
        return $this->hasMany(TemplatePassengerCategory::class);
    }

    public function templateAddons(): HasMany
    {
        return $this->hasMany(TemplateAddon::class);
    }

    public function requirementPreset(): BelongsTo
    {
        return $this->belongsTo(RequirementPreset::class);
    }
}
