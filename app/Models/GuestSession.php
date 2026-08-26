<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GuestSession extends Model
{
    use \Illuminate\Database\Eloquent\Concerns\HasUuids;

    protected $fillable = [
        'first_name',
        'email',
        'phone',
        'trip_instance_id',
        'hold_id',
        'expires_at',
    ];
    
    protected $casts = [
        'expires_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Case-Insensitive Uniqueness Fix: normalized here (not just on Customer) so
        // CreateBookingService::execute() -- guardrail-protected, not modified -- reads an
        // already-lowercase $guestSession->email when it builds Customer::firstOrCreate()'s
        // search criteria. Same rationale as Customer::booted()/User::booted().
        static::saving(function ($session) {
            if (!empty($session->email)) {
                $session->email = \Illuminate\Support\Str::lower(trim($session->email));
            }
        });
    }

    public function hold()
    {
        return $this->belongsTo(\App\Models\InventoryLedger::class, 'hold_id');
    }
}
