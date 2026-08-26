<?php

namespace App\Services;

use App\Models\TripBusAssignment;
use App\Models\TripInstance;

/**
 * Bus/Fleet redesign Ticket 2 — keeps TripInstance.available_seats in sync with the sum of a
 * trip's active TripBusAssignment rows, WITHOUT touching InventoryService: InventoryService
 * keeps reading the exact same plain column exactly as before, this service is only what feeds
 * that column correctly once a trip has bus assignments. Mirrors RoomAssignmentService's own
 * boundary from the Hotel/Rooming redesign Ticket 2 — read the new feature's data, write only
 * to columns/tables the new feature owns, never alter the existing hardened service's logic.
 */
class TripFleetService
{
    /**
     * Recalculates and persists available_seats for one trip from its current bus assignments.
     * A plain query-builder update (not $model->save()) — this deliberately does NOT go through
     * TripInstance's Eloquent save lifecycle, so it can never trip the model's own updating()
     * guard (currency can't change once bookings exist) or any other unrelated model event.
     *
     * Trips with zero bus assignments are left untouched: this service only manages
     * available_seats once a trip has opted in by having at least one bus assignment (the exact
     * same gate hasAnyBusAssignments() exposes to the Filament UI lock) — a trip that has never
     * used fleet management, or that just had its last bus assignment removed, keeps whatever
     * value was already there, which staff can freely hand-edit again once unlocked.
     */
    public function recalculateAvailableSeats(int $tripInstanceId): void
    {
        if (!TripBusAssignment::where('trip_instance_id', $tripInstanceId)->exists()) {
            return;
        }

        $totalCapacity = TripBusAssignment::where('trip_instance_id', $tripInstanceId)->sum('capacity');

        TripInstance::whereKey($tripInstanceId)->update(['available_seats' => $totalCapacity]);
    }

    public function hasAnyBusAssignments(TripInstance $tripInstance): bool
    {
        return $tripInstance->tripBusAssignments()->exists();
    }
}
