<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class TemplatePassengerCategory extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'template_passenger_categories';

    protected $fillable = [
        'tenant_id',
        'trip_template_id',
        'global_pricing_tier_id',
        'name',
        'price',
    ];

    protected $casts = [
        'price' => \App\Casts\MoneyCast::class,
    ];

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (auth()->check()) {
                $model->tenant_id ??= \Filament\Facades\Filament::getTenant()?->id;
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function tripTemplate(): BelongsTo
    {
        return $this->belongsTo(TripTemplate::class);
    }

    public function passengerCategory(): BelongsTo
    {
        return $this->belongsTo(PassengerCategory::class);
    }
}
