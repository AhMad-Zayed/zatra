<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown by RoomAssignmentService when a room_instance is already at its RoomType's
 * capacity_per_room (or, for a single-occupancy selection, already occupied). Deliberately NOT
 * part of the InventoryExhaustedException family (InsufficientSeatsException/
 * InsufficientRoomsException) — those fire during booking creation and are handled by every
 * booking-creation entry point's catch block; this fires during staff room assignment, a
 * completely separate call site with its own UI-facing handling.
 */
class RoomCapacityExceededException extends Exception
{
    //
}
