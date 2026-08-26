<?php

namespace App\Observers;

use App\Models\TripBusAssignment;
use App\Services\TripFleetService;

/**
 * Bus/Fleet redesign Ticket 2 — keeps TripInstance.available_seats in sync with bus assignment
 * changes. Mirrors PassengerObserver's existing shape in this codebase (an Observer delegating
 * to a Service, registered in AppServiceProvider).
 */
class TripBusAssignmentObserver
{
    public function __construct(protected TripFleetService $tripFleetService)
    {
    }

    public function created(TripBusAssignment $assignment): void
    {
        $this->tripFleetService->recalculateAvailableSeats($assignment->trip_instance_id);
    }

    public function updated(TripBusAssignment $assignment): void
    {
        $this->tripFleetService->recalculateAvailableSeats($assignment->trip_instance_id);

        // Defensive: a bus assignment moving between trips isn't a real UI flow today, but if it
        // ever happened, the trip it left behind must also be recalculated — otherwise that
        // trip's available_seats would keep including a capacity it no longer has.
        $originalTripInstanceId = $assignment->getOriginal('trip_instance_id');
        if ($originalTripInstanceId && $originalTripInstanceId !== $assignment->trip_instance_id) {
            $this->tripFleetService->recalculateAvailableSeats($originalTripInstanceId);
        }
    }

    public function deleted(TripBusAssignment $assignment): void
    {
        $this->tripFleetService->recalculateAvailableSeats($assignment->trip_instance_id);
    }

    public function restored(TripBusAssignment $assignment): void
    {
        $this->tripFleetService->recalculateAvailableSeats($assignment->trip_instance_id);
    }
}
