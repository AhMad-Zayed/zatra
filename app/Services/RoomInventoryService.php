<?php

namespace App\Services;

use App\Exceptions\InsufficientRoomsException;
use App\Models\Booking;
use App\Models\RoomInventoryLedger;
use App\Models\RoomType;
use Illuminate\Support\Collection;

/**
 * Mirrors InventoryService's proven ledger pattern (lockForUpdate, in-transaction capacity
 * recheck, ledger-sum-based availability) exactly, as a new, fully separate service — never
 * modifying or entangling with the seat inventory code. Hotel/Rooming redesign Ticket 2.
 *
 * LOCK ORDERING (required, do not reverse): every caller of consumeForBooking() must already
 * hold a lockForUpdate() on the relevant TripInstance BEFORE calling this method (exactly as
 * CreateBookingService::execute() already does at its very first step, for seats). This method
 * then locks the RoomType row(s) SECOND, never first. TripInstance-before-RoomType is the only
 * lock order any code path in this app may ever take when both are needed in the same
 * transaction; taking them in reverse anywhere would create a real deadlock risk between two
 * concurrent transactions racing for the opposite order. There is currently only one caller
 * (CreateBookingService::execute()), so this is a documented discipline to maintain going
 * forward, not yet a contended path.
 */
class RoomInventoryService
{
    /**
     * Consume room inventory for a set of booking-time room-type selections.
     *
     * SECURITY (required, not optional): each room_type_id is resolved strictly scoped to
     * $booking->trip_instance_id via findOrFail() BEFORE any lock is taken — a room_type_id
     * belonging to a different trip instance throws a ModelNotFoundException (404-equivalent)
     * rather than silently consuming/charging against another trip's inventory. This is the
     * IDOR guard confirmed as required in the Ticket 2 investigation (Section C): RoomType price
     * fields carry no independent currency, so there is nothing to validate there — this
     * trip-instance-scoping check is the real integrity guard for this data.
     *
     * @param array<int, array{room_type_id: int, quantity: int, occupancy_type?: string}> $roomSelections
     * @return Collection<int, array{room_type: RoomType, quantity: int, occupancy_type: string}>
     *     Resolved selections, for the caller (CreateBookingService) to price and snapshot into
     *     BookingRoomSelection rows. This service deliberately does no pricing — mirrors how
     *     InventoryService never touches pricing either, leaving that to the caller.
     * @throws InsufficientRoomsException
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function consumeForBooking(Booking $booking, array $roomSelections): Collection
    {
        $resolved = collect();

        foreach ($roomSelections as $selection) {
            $quantity = (int) ($selection['quantity'] ?? 0);
            if ($quantity <= 0) {
                continue;
            }

            $occupancyType = $selection['occupancy_type'] ?? 'shared';

            // IDOR guard + lock (second, per the lock-ordering contract above).
            $roomType = RoomType::whereHas('tripStayLegHotelOption.tripStayLeg', function ($q) use ($booking) {
                $q->where('trip_instance_id', $booking->trip_instance_id);
            })
                ->lockForUpdate()
                ->findOrFail($selection['room_type_id']);

            $consumed = RoomInventoryLedger::where('room_type_id', $roomType->id)
                ->where(function ($q) {
                    $q->whereNull('expires_at')
                      ->orWhere('expires_at', '>', now());
                })
                ->sum('quantity');

            $remaining = $roomType->room_count + $consumed;

            if ($remaining < $quantity) {
                throw new InsufficientRoomsException(
                    "لا توجد غرف كافية من نوع '{$roomType->name}'. المتاح: " . max(0, $remaining)
                );
            }

            RoomInventoryLedger::create([
                'tenant_id' => $booking->tenant_id,
                'room_type_id' => $roomType->id,
                'booking_id' => $booking->id,
                'quantity' => -$quantity,
                'type' => 'confirmed',
            ]);

            $resolved->push([
                'room_type' => $roomType,
                'quantity' => $quantity,
                'occupancy_type' => $occupancyType,
            ]);
        }

        return $resolved;
    }

    /**
     * Release room inventory for a cancelled booking. Idempotent: safe to call multiple times.
     * Same idempotency shape (and same accepted P0-5-documented edge case around partial
     * releases) as InventoryService::releaseForCancellation() — treats any existing
     * type='cancelled' row for the booking as "already released."
     */
    public function releaseForCancellation(Booking $booking): void
    {
        $alreadyReleased = RoomInventoryLedger::where('booking_id', $booking->id)
            ->where('type', 'cancelled')
            ->exists();

        if ($alreadyReleased) {
            return;
        }

        // Grouped per room_type_id: a single booking can have consumed multiple different room
        // types, and each reversal row needs its own room_type_id.
        $byRoomType = RoomInventoryLedger::where('booking_id', $booking->id)
            ->selectRaw('room_type_id, sum(quantity) as net')
            ->groupBy('room_type_id')
            ->get();

        foreach ($byRoomType as $row) {
            if ($row->net < 0) {
                RoomInventoryLedger::create([
                    'tenant_id' => $booking->tenant_id,
                    'room_type_id' => $row->room_type_id,
                    'booking_id' => $booking->id,
                    'quantity' => abs($row->net),
                    'type' => 'cancelled',
                ]);
            }
        }
    }
}
