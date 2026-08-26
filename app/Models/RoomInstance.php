<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A single, addressable physical room slot within a RoomType — the drag-and-drop target in the
 * rooming UI. Lazily created by RoomAssignmentService::ensureRoomInstancesExist(), not owned by
 * any one booking. Hotel/Rooming redesign Ticket 3.
 */
class RoomInstance extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'room_type_id',
        'room_number',
    ];

    protected $casts = [
        'room_number' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(RoomAssignment::class);
    }
}
