<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class TripAddon extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'trip_instance_id',
        'name',
        'price',
        'max_quantity',
    ];

    protected $casts = [
        'price' => \App\Casts\MoneyCast::class,
        'max_quantity' => 'integer',
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

    public function tripInstance(): BelongsTo
    {
        return $this->belongsTo(TripInstance::class);
    }
}
