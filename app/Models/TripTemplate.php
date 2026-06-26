<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TripTemplate extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'title',
        'description',
        'base_price',
        'passenger_requirements',
    ];

    protected $casts = [
        'base_price' => 'decimal:2',
        'passenger_requirements' => 'array',
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

    public function templatePricingTiers(): HasMany
    {
        return $this->hasMany(TemplatePricingTier::class);
    }

    public function templateAddons(): HasMany
    {
        return $this->hasMany(TemplateAddon::class);
    }
}
