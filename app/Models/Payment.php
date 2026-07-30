<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Exception;

class Payment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'booking_id',
        'amount',
        'payment_method',
        'reference_number',
        'type',
        'received_by',
    ];

    protected $casts = [
        'amount' => \App\Casts\MoneyCast::class,
        'type' => \App\Enums\PaymentType::class,
    ];

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (auth()->check()) {
                $model->tenant_id ??= \Filament\Facades\Filament::getTenant()?->id;
            }
        });

        static::updating(function ($model) {
            throw new Exception("Payments are strictly immutable and cannot be updated.");
        });

        static::deleting(function ($model) {
            throw new Exception("Payments are strictly immutable and cannot be deleted.");
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
