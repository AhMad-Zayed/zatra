<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mirrors InventoryLedger exactly (same immutability guard, same ledger-sum-over-expires_at
 * pattern) — a fully separate table/model, never sharing rows, locks, or state with the seat
 * ledger. Hotel/Rooming redesign Ticket 2.
 */
class RoomInventoryLedger extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function ($ledger) {
            if (empty($ledger->tenant_id) && auth()->check()) {
                try {
                    $ledger->tenant_id = \Filament\Facades\Filament::getTenant()?->id;
                } catch (\Throwable $e) {
                    if ($ledger->booking_id) {
                        $ledger->tenant_id = \App\Models\Booking::find($ledger->booking_id)?->tenant_id;
                    } elseif ($ledger->room_type_id) {
                        $ledger->tenant_id = \App\Models\RoomType::find($ledger->room_type_id)?->tenant_id;
                    }
                }
            }
        });

        static::updating(function () {
            throw new \RuntimeException(
                'ROOM INVENTORY INTEGRITY VIOLATION: RoomInventoryLedger records are immutable. Create a new entry instead.'
            );
        });

        static::deleting(function () {
            throw new \RuntimeException(
                'ROOM INVENTORY INTEGRITY VIOLATION: RoomInventoryLedger records cannot be deleted.'
            );
        });
    }

    protected $fillable = [
        'tenant_id',
        'room_type_id',
        'booking_id',
        'quantity',
        'type',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'quantity' => 'integer',
    ];

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
