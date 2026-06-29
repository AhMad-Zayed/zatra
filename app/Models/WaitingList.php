<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Enums\WaitingListStatusEnum;

class WaitingList extends Model
{
    protected $fillable = [
        'tenant_id',
        'trip_instance_id',
        'customer_name',
        'phone_number',
        'customer_email',
        'status',
        'notified_at',
    ];

    protected $casts = [
        'status' => WaitingListStatusEnum::class,
        'notified_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function tripInstance(): BelongsTo
    {
        return $this->belongsTo(TripInstance::class);
    }
}
