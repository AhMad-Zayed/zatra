<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PickupRoute extends Model
{
    use \Illuminate\Database\Eloquent\Factories\HasFactory, \Illuminate\Database\Eloquent\SoftDeletes;

    protected $fillable = ['tenant_id', 'name', 'description', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (auth()->check()) {
                $model->tenant_id ??= \Filament\Facades\Filament::getTenant()?->id;
            }
        });
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function pickupPoints()
    {
        return $this->hasMany(PickupPoint::class)->orderBy('order');
    }

    public function tripInstances()
    {
        return $this->belongsToMany(TripInstance::class, 'trip_instance_pickup_routes');
    }
}
