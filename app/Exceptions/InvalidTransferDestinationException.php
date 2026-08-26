<?php

namespace App\Exceptions;

/**
 * Thrown by BookingService::transferBooking() when the destination TripInstance's status makes
 * it an invalid transfer target (Cancelled, Completed, InProgress) -- sibling to
 * InsufficientSeatsException/InsufficientRoomsException in naming, but a distinct lifecycle-state
 * problem rather than a capacity one, so it does NOT extend InventoryExhaustedException.
 */
class InvalidTransferDestinationException extends \Exception
{
    //
}
