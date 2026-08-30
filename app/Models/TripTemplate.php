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

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('cover')->singleFile();
        $this->addMediaCollection('gallery');
    }

    /**
     * Storefront redesign Phase B (Section D): the catalog card and trip-details gallery
     * previously served the raw, full-resolution original for every image, with no
     * loading="lazy" and no responsive srcset -- a real, measured performance gap on a
     * higher-traffic customer-facing surface. 'card' covers the catalog grid and gallery
     * thumbnails; 'card-2x' is the same crop at double the width, so the view can offer a real
     * srcset instead of one fixed size.
     */
    public function registerMediaConversions(?\Spatie\MediaLibrary\MediaCollections\Models\Media $media = null): void
    {
        $this->addMediaConversion('card')
            ->width(800)
            ->quality(80)
            ->nonQueued();

        $this->addMediaConversion('card-2x')
            ->width(1200)
            ->quality(75)
            ->nonQueued();
    }

    protected $fillable = [
        'tenant_id',
        'title',
        'currency',
        'trip_type',
        'slug',
        'is_active',
        'description',
        'base_price',
        'duration_days',
        'destination_latitude',
        'destination_longitude',
        'passenger_requirements',
        'itinerary_data',
        'includes',
        'excludes',
        'requirement_preset_id',
        'deposit_percentage',
        'deposit_enabled',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'trip_type' => \App\Enums\TripTypeEnum::class,
        'base_price' => \App\Casts\MoneyCast::class,
        'duration_days' => 'integer',
        'destination_latitude' => 'decimal:7',
        'destination_longitude' => 'decimal:7',
        'passenger_requirements' => 'array',
        'itinerary_data' => 'array',
        'includes' => 'array',
        'excludes' => 'array',
        'deposit_enabled' => 'boolean',
        'deposit_percentage' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::updating(function ($model) {
            if ($model->isDirty('currency')) {
                if ($model->tripInstances()->exists()) {
                    throw new \RuntimeException("Currency cannot be changed because trip instances already exist.");
                }
            }
        });
    }

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

    /**
     * Storefront price display fallback: base_price is often left at 0 when a trip is actually
     * priced entirely through its TripPassengerCategory records instead -- fixes the live-confirmed
     * "0 دولار" display bug (docs/STOREFRONT_UX_AUDIT.md, Friction Point #2). Falls back to the
     * lowest passenger-category price across this template's bookable instances.
     */
    public function getStartingPriceAttribute(): float
    {
        if ((float) $this->base_price > 0) {
            return (float) $this->base_price;
        }

        // A caller that already eager-loaded tripInstances (with tripPassengerCategories nested)
        // gets that collection reused as-is -- querying fresh here regardless of what was already
        // loaded was a measured N+1 on the catalog listing (5 queries -> 11 for a single card,
        // confirmed while investigating the storefront redesign's Section D). Only a caller that
        // never loaded the relation at all (e.g. the single-template trip details page) still
        // pays for one fresh query here, which is fine at that scale.
        $instances = $this->relationLoaded('tripInstances')
            ? $this->tripInstances
            : $this->tripInstances()->bookable()->with('tripPassengerCategories')->get();

        return (float) ($instances->flatMap->tripPassengerCategories->min('price') ?? 0.0);
    }

    protected static function booted()
    {
        static::creating(function ($template) {
            if (empty($template->slug)) {
                $template->slug = \Illuminate\Support\Str::slug($template->title . '-' . uniqid());
            }
        });
        
        static::updating(function ($template) {
            if (empty($template->slug)) {
                $template->slug = \Illuminate\Support\Str::slug($template->title . '-' . $template->id);
            }
        });
    }
}
