<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Enums\WaitingListStatusEnum;

class WaitingList extends Model
{
    protected $fillable = [
        'tenant_id',
        'seats_requested',
        'customer_name',
        'phone_number',
        'customer_email',
        'status',
        'notified_at',
        'hold_id',
    ];

    protected $casts = [
        'status' => WaitingListStatusEnum::class,
        'notified_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function tripInstances()
    {
        return $this->belongsToMany(TripInstance::class, 'trip_instance_waiting_list')
                    ->withPivot('priority')
                    ->orderByPivot('priority', 'asc')
                    ->withTimestamps();
    }
}
