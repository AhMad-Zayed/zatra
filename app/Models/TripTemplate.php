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

    protected $fillable = [
        'tenant_id',
        'title',
        'currency',
        'slug',
        'is_active',
        'description',
        'base_price',
        'passenger_requirements',
        'requirement_preset_id',
        'deposit_percentage',
        'deposit_enabled',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'base_price' => \App\Casts\MoneyCast::class,
        'passenger_requirements' => 'array',
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
