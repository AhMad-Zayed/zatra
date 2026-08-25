<?php

namespace App\Exceptions;

/**
 * Sibling to InsufficientSeatsException — extends InventoryExhaustedException so every existing
 * booking-creation entry point's generic `catch (InventoryExhaustedException $e)` block already
 * handles this correctly with zero changes.
 */
class InsufficientRoomsException extends InventoryExhaustedException
{
    //
}
