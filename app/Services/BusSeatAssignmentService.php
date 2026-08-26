<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Exceptions\BusCapacityExceededException;
use App\Models\Passenger;
use App\Models\TripBusAssignment;
use App\Models\TripInstance;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Bus/Fleet redesign Ticket 3 — post-booking staff seat assignment, the bus equivalent of
 * RoomAssignmentService. Reads existing booking/passenger data and writes only to
 * passengers.trip_bus_assignment_id / passengers.seat_number (both already-existing columns —
 * this ticket only adds the trip_bus_assignment_id disambiguator). Never touches
 * CreateBookingService, InventoryService, RoomInventoryService, BookingService, TripService,
 * RequirementValidationService, or any Policy class.
 *
 * Simpler than RoomAssignmentService by design: buses have flat capacity only, no
 * single/shared-occupancy distinction to enforce.
 */
class BusSeatAssignmentService
{
    /**
     * Assigns (or moves) a passenger onto a bus, auto-picking the lowest free seat number
     * within that bus. Locked capacity check under a row lock, mirroring
     * RoomAssignmentService::assignPassenger()'s exact shape.
     *
     * @throws BusCapacityExceededException
     */
    public function assignPassengerToBus(Passenger $passenger, TripBusAssignment $bus, ?User $assignedBy = null): Passenger
    {
        return DB::transaction(function () use ($passenger, $bus) {
            /** @var TripBusAssignment $lockedBus */
            $lockedBus = TripBusAssignment::query()->whereKey($bus->id)->lockForUpdate()->firstOrFail();

            // Existing occupants, excluding this passenger if they're already on this bus
            // (re-dropping onto the same bus must not count against its own capacity).
            $occupants = Passenger::where('trip_bus_assignment_id', $lockedBus->id)
                ->where('id', '!=', $passenger->id)
                ->get();

            if ($occupants->count() >= $lockedBus->capacity) {
                throw new BusCapacityExceededException('هذه الحافلة ممتلئة بالكامل.');
            }

            // Idempotent re-drop into the same bus keeps the existing seat number rather than
            // reassigning a new one.
            if ($passenger->trip_bus_assignment_id === $lockedBus->id && $passenger->seat_number) {
                return $passenger;
            }

            $takenSeats = $occupants->pluck('seat_number')->filter()->map(fn ($s) => (int) $s)->all();
            $seatNumber = 1;
            while (in_array($seatNumber, $takenSeats, true)) {
                $seatNumber++;
            }

            $passenger->update([
                'trip_bus_assignment_id' => $lockedBus->id,
                'seat_number' => (string) $seatNumber,
            ]);

            return $passenger->fresh();
        });
    }

    public function unassignPassenger(Passenger $passenger): void
    {
        $passenger->update([
            'trip_bus_assignment_id' => null,
            'seat_number' => null,
        ]);
    }

    /**
     * Everything the assignment board needs to render. "Unassigned" deliberately includes any
     * passenger with no trip_bus_assignment_id — including one who self-selected a seat_number
     * via CustomerBookingPortal back when the trip had at most one bus, before a second bus made
     * that seat number ambiguous (Phase 0 Section C / Ticket 2's portal guard). Rather than
     * guessing which bus such a passenger belongs to, they surface here for staff to explicitly
     * (re)confirm through this validated UI — the board shows their old seat_number as a hint
     * (see AssignBuses' Blade view) so staff have context, but nothing is silently backfilled.
     *
     * @return array{unassigned: Collection<int, Passenger>, buses: Collection<int, TripBusAssignment>}
     */
    public function getBoardData(TripInstance $tripInstance): array
    {
        $buses = $tripInstance->tripBusAssignments()
            ->with(['passengers.booking.customer', 'vehicle'])
            ->get();

        $unassigned = Passenger::whereHas('booking', function ($q) use ($tripInstance) {
            $q->where('trip_instance_id', $tripInstance->id)
                ->where('booking_status', '!=', BookingStatus::Cancelled);
        })
            ->whereNull('trip_bus_assignment_id')
            ->with('booking.customer')
            ->get();

        return ['unassigned' => $unassigned, 'buses' => $buses];
    }

    /**
     * Greedy best-fit-decreasing bin packing, the same shape as
     * RoomAssignmentService::autoAssign() minus the single-occupancy exclusivity (buses have no
     * equivalent concept — flat capacity only). No row locking here, matching
     * RoomAssignmentService's own reasoning: a low-concurrency, staff-only placement action, not
     * a customer-facing reservation with holds/expiry.
     *
     * @return array{assigned: int, unassigned: Collection<int, Passenger>}
     */
    public function autoAssign(TripInstance $tripInstance, ?User $actor = null): array
    {
        return DB::transaction(function () use ($tripInstance) {
            $buses = $tripInstance->tripBusAssignments()->get()->map(function (TripBusAssignment $bus) {
                $takenSeats = Passenger::where('trip_bus_assignment_id', $bus->id)
                    ->pluck('seat_number')
                    ->filter()
                    ->map(fn ($s) => (int) $s)
                    ->values()
                    ->all();

                return (object) [
                    'assignment' => $bus,
                    'capacity' => $bus->capacity,
                    'used' => count($takenSeats),
                    'takenSeats' => $takenSeats,
                ];
            });

            $unassigned = $this->getBoardData($tripInstance)['unassigned'];
            $groups = $unassigned->groupBy('booking_id')->sortByDesc(fn (Collection $g) => $g->count());

            $placedPassengerIds = [];

            foreach ($groups as $group) {
                foreach ($this->placeGroup($group, $buses) as $passengerId => $placement) {
                    Passenger::find($passengerId)?->update([
                        'trip_bus_assignment_id' => $placement['bus_id'],
                        'seat_number' => (string) $placement['seat'],
                    ]);
                    $placedPassengerIds[] = $passengerId;
                }
            }

            $stillUnassigned = $unassigned->reject(fn (Passenger $p) => in_array($p->id, $placedPassengerIds, true))->values();

            return [
                'assigned' => count($placedPassengerIds),
                'unassigned' => $stillUnassigned,
            ];
        });
    }

    /**
     * Places one booking-group across $buses, mutating each bus object's ->used/->takenSeats in
     * place as it goes (threading capacity state forward across successive groups, same as
     * RoomAssignmentService::placeGroup()).
     *
     * @param  Collection<int, Passenger>  $group
     * @param  Collection<int, object{assignment: TripBusAssignment, capacity: int, used: int, takenSeats: array<int>}>  $buses
     * @return array<int, array{bus_id: int, seat: int}> passenger_id => placement
     */
    private function placeGroup(Collection $group, Collection $buses): array
    {
        $plan = [];
        $remaining = $group->values();
        $groupSize = $remaining->count();

        // Best fit: the bus whose remaining capacity is the smallest value still >= group size,
        // so a large bus isn't wasted on a small group when a snugger one is free.
        $bestFit = $buses->filter(fn (object $b) => ($b->capacity - $b->used) >= $groupSize)
            ->sortBy(fn (object $b) => $b->capacity - $b->used)
            ->first();

        if ($bestFit) {
            foreach ($remaining as $passenger) {
                $seat = $this->nextFreeSeat($bestFit);
                $plan[$passenger->id] = ['bus_id' => $bestFit->assignment->id, 'seat' => $seat];
                $bestFit->used++;
                $bestFit->takenSeats[] = $seat;
            }

            return $plan;
        }

        // No single bus fits the whole group — split across the emptiest buses first, to keep
        // as many members together as capacity allows.
        $toPlace = $remaining->values();
        while ($toPlace->isNotEmpty()) {
            $bus = $buses->filter(fn (object $b) => ($b->capacity - $b->used) > 0)
                ->sortByDesc(fn (object $b) => $b->capacity - $b->used)
                ->first();

            if (!$bus) {
                break; // truly out of capacity — whatever's left in $toPlace stays unassigned
            }

            $slot = $bus->capacity - $bus->used;
            $chunk = $toPlace->splice(0, $slot);

            foreach ($chunk as $passenger) {
                $seat = $this->nextFreeSeat($bus);
                $plan[$passenger->id] = ['bus_id' => $bus->assignment->id, 'seat' => $seat];
                $bus->used++;
                $bus->takenSeats[] = $seat;
            }
        }

        return $plan;
    }

    private function nextFreeSeat(object $bus): int
    {
        $seat = 1;
        while (in_array($seat, $bus->takenSeats, true)) {
            $seat++;
        }

        return $seat;
    }
}
