<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryLedger extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function ($ledger) {
            if (empty($ledger->tenant_id) && auth()->check()) {
                try {
                    $ledger->tenant_id = \Filament\Facades\Filament::getTenant()?->id;
                } catch (\Throwable $e) {
                    // Outside Filament context — set from related booking or trip
                    if ($ledger->booking_id) {
                        $ledger->tenant_id = \App\Models\Booking::find($ledger->booking_id)?->tenant_id;
                    } elseif ($ledger->trip_instance_id) {
                        $ledger->tenant_id = \App\Models\TripInstance::find($ledger->trip_instance_id)?->tenant_id;
                    }
                }
            }
        });

        static::updating(function () {
            throw new \RuntimeException(
                'INVENTORY INTEGRITY VIOLATION: InventoryLedger records are immutable. Create a new entry instead.'
            );
        });

        static::deleting(function () {
            throw new \RuntimeException(
                'INVENTORY INTEGRITY VIOLATION: InventoryLedger records cannot be deleted.'
            );
        });
    }

    protected $fillable = [
        'tenant_id',
        'trip_instance_id',
        'booking_id',
        'quantity',
        'type',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'quantity' => 'integer',
    ];

    public function tripInstance(): BelongsTo
    {
        return $this->belongsTo(TripInstance::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
