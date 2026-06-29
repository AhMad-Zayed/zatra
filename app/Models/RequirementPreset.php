<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RequirementPreset extends Model
{
    use \Illuminate\Database\Eloquent\Factories\HasFactory, \Illuminate\Database\Eloquent\SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'title',
        'items',
    ];

    protected $casts = [
        'items' => 'array',
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
}
