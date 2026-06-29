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

    public function hold()
    {
        return $this->belongsTo(\App\Models\InventoryLedger::class, 'hold_id');
    }
}
