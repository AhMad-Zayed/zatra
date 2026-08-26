<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\OccupancyTypeEnum;
use App\Exceptions\RoomCapacityExceededException;
use App\Models\BookingRoomSelection;
use App\Models\Passenger;
use App\Models\RoomAssignment;
use App\Models\RoomInstance;
use App\Models\RoomType;
use App\Models\TripStayLegHotelOption;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Hotel/Rooming redesign Ticket 3 — post-booking staff room assignment. Reads existing
 * booking/room-selection data (Ticket 1/2) and writes only to its own new tables
 * (room_instances, room_assignments). Never touches CreateBookingService, InventoryService,
 * RoomInventoryService, BookingService, TripService, or any Policy class.
 */
class RoomAssignmentService
{
    /**
     * Lazily materializes room_instances up to RoomType.room_count. Idempotent — safe to call
     * every time the assignment board loads.
     *
     * @return Collection<int, RoomInstance>
     */
    public function ensureRoomInstancesExist(RoomType $roomType): Collection
    {
        for ($number = 1; $number <= $roomType->room_count; $number++) {
            RoomInstance::firstOrCreate(
                ['room_type_id' => $roomType->id, 'room_number' => $number],
                ['tenant_id' => $roomType->tenant_id]
            );
        }

        return $roomType->roomInstances()->get();
    }

    /**
     * Materializes room instances for every room type under a hotel option — what the assignment
     * board calls once on load.
     *
     * @return Collection<int, RoomInstance>
     */
    public function ensureRoomInstancesExistForHotelOption(TripStayLegHotelOption $hotelOption): Collection
    {
        return $hotelOption->roomTypes()
            ->get()
            ->flatMap(fn (RoomType $roomType) => $this->ensureRoomInstancesExist($roomType));
    }

    /**
     * Assigns (or moves) a passenger into a room instance, enforcing capacity_per_room as a hard
     * limit under a row lock — the same lock-then-verify shape used elsewhere in this codebase
     * for inventory, scaled to this feature's low-concurrency, non-time-limited nature (a
     * permanent staff placement, not a customer-facing reservation with holds/expiry).
     *
     * A passenger belonging to a booking_room_selection with occupancy_type = 'single' both
     * requires an empty room and, once placed, caps that room at 1 for anyone else — this
     * enforces the single-supplement customers already paid for, not a new business rule.
     *
     * @throws RoomCapacityExceededException
     */
    public function assignPassenger(Passenger $passenger, RoomInstance $roomInstance, ?User $assignedBy = null): RoomAssignment
    {
        return DB::transaction(function () use ($passenger, $roomInstance, $assignedBy) {
            /** @var RoomInstance $lockedInstance */
            $lockedInstance = RoomInstance::query()
                ->whereKey($roomInstance->id)
                ->lockForUpdate()
                ->firstOrFail();

            $roomType = $lockedInstance->roomType;

            // Existing occupants, excluding this passenger if they're already in this room
            // (re-dropping into the same room must not count against its own capacity).
            $occupants = RoomAssignment::query()
                ->where('room_instance_id', $lockedInstance->id)
                ->where('passenger_id', '!=', $passenger->id)
                ->with('passenger')
                ->get();

            if ($occupants->count() >= $roomType->capacity_per_room) {
                throw new RoomCapacityExceededException('هذه الغرفة ممتلئة بالكامل.');
            }

            $incomingRequiresSingle = $this->requiresSingleOccupancy($passenger, $roomType->id);
            $roomAlreadyHasSingleOccupant = $occupants->contains(
                fn (RoomAssignment $a) => $this->requiresSingleOccupancy($a->passenger, $roomType->id)
            );

            if ($occupants->isNotEmpty() && ($incomingRequiresSingle || $roomAlreadyHasSingleOccupant)) {
                throw new RoomCapacityExceededException('هذه الغرفة مخصصة للإقامة الفردية ولا يمكن مشاركتها.');
            }

            return RoomAssignment::updateOrCreate(
                ['passenger_id' => $passenger->id],
                [
                    'tenant_id' => $passenger->tenant_id,
                    'room_instance_id' => $lockedInstance->id,
                    'booking_id' => $passenger->booking_id,
                    'assigned_by' => $assignedBy?->id,
                ]
            );
        });
    }

    public function unassignPassenger(Passenger $passenger): void
    {
        RoomAssignment::where('passenger_id', $passenger->id)->delete();
    }

    /**
     * Everything the assignment board needs to render: the unassigned pool and every room
     * (occupied or not) with its current occupants. Centralized here — read-only, no writes —
     * so the Livewire page stays a thin consumer and tests can exercise the same query the UI
     * renders from.
     *
     * @return array{unassigned: Collection<int, Passenger>, roomTypes: Collection<int, RoomType>}
     */
    public function getBoardData(TripStayLegHotelOption $hotelOption): array
    {
        $roomTypes = $hotelOption->roomTypes()
            ->where('is_active', true)
            ->with(['roomInstances.assignments.passenger.booking.customer'])
            ->get();

        $unassigned = $this->unassignedPassengers($roomTypes->pluck('id'))
            ->load('booking.customer', 'tripPassengerCategory');

        return [
            'unassigned' => $unassigned,
            'roomTypes' => $roomTypes,
        ];
    }

    /**
     * @return Collection<int, Passenger>
     */
    public function unassignedPassengers(Collection $roomTypeIds): Collection
    {
        return $this->findUnassignedPassengers($roomTypeIds);
    }

    /**
     * Greedy best-fit-decreasing bin packing: unassigned passengers grouped by booking (largest
     * groups first), each group placed into the tightest single room that fits it whole where
     * possible, otherwise split across the emptiest rooms first to keep as many members together
     * as capacity allows. Never invents capacity — passengers that can't fit anywhere are
     * reported back, not silently dropped.
     *
     * @return array{assigned: int, unassigned: Collection<int, Passenger>}
     */
    public function autoAssign(TripStayLegHotelOption $hotelOption, ?User $actor = null): array
    {
        return DB::transaction(function () use ($hotelOption, $actor) {
            $roomTypes = $hotelOption->roomTypes()->where('is_active', true)->get();
            $roomTypeIds = $roomTypes->pluck('id');

            foreach ($roomTypes as $roomType) {
                $this->ensureRoomInstancesExist($roomType);
            }

            $unassigned = $this->unassignedPassengers($roomTypeIds);

            // Plain stdClass objects, not arrays — PHP arrays are value types, so
            // $collection[$key]['used']++ silently fails to persist through Collection's
            // ArrayAccess (confirmed via a live smoke test before this was caught: it let a
            // 2-capacity room silently accept 3 passengers). Objects mutate in place correctly.
            $rooms = RoomInstance::query()
                ->whereIn('room_type_id', $roomTypeIds)
                ->with('roomType')
                ->get()
                ->mapWithKeys(function (RoomInstance $instance) {
                    $occupants = RoomAssignment::where('room_instance_id', $instance->id)->with('passenger')->get();
                    $singleLocked = $occupants->contains(
                        fn (RoomAssignment $a) => $this->requiresSingleOccupancy($a->passenger, $instance->room_type_id)
                    );

                    return [$instance->id => (object) [
                        'instance' => $instance,
                        'capacity' => $singleLocked ? 1 : $instance->roomType->capacity_per_room,
                        'used' => $occupants->count(),
                    ]];
                });

            $groups = $unassigned->groupBy('booking_id')->sortByDesc(fn (Collection $g) => $g->count());

            $placedPassengerIds = [];

            foreach ($groups as $group) {
                // placeGroup() mutates $rooms' ->used counters directly as it plans — the single
                // source of truth for capacity bookkeeping, threaded forward across groups.
                foreach ($this->placeGroup($group, $rooms) as $roomId => $passengerIds) {
                    foreach ($passengerIds as $passengerId) {
                        $passenger = $group->firstWhere('id', $passengerId);

                        RoomAssignment::updateOrCreate(
                            ['passenger_id' => $passenger->id],
                            [
                                'tenant_id' => $passenger->tenant_id,
                                'room_instance_id' => $roomId,
                                'booking_id' => $passenger->booking_id,
                                'assigned_by' => $actor?->id,
                            ]
                        );

                        $placedPassengerIds[] = $passenger->id;
                    }
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
     * Places one booking-group, mutating $rooms' ->used counters in place as it goes (the
     * objects are shared by reference-handle with the caller, so this threads capacity state
     * forward correctly across successive calls for later groups). Returns
     * [room_instance_id => [passenger_id, ...]].
     *
     * @param  Collection<int, Passenger>  $group
     * @param  Collection<int, object{instance: RoomInstance, capacity: int, used: int}>  $rooms
     * @return array<int, array<int, int>>
     */
    private function placeGroup(Collection $group, Collection $rooms): array
    {
        $plan = [];
        $remaining = $group->values();

        $singleMembers = $remaining->filter(fn (Passenger $p) => $this->requiresSingleOccupancy($p, null));
        $sharedMembers = $remaining->reject(fn (Passenger $p) => $singleMembers->contains('id', $p->id));

        // Single-occupancy passengers each need their own untouched, empty room. Locking
        // ->capacity to 1 here (not just bumping ->used) matters: without it, a later group
        // in this same run could still see "remaining capacity" in this room and get bin-packed
        // into it, since ->capacity was only pre-computed from assignments that existed before
        // this autoAssign() call started.
        foreach ($singleMembers as $passenger) {
            $room = $rooms->first(fn (object $r) => $r->used === 0 && $r->capacity >= 1);
            if (!$room) {
                continue; // no empty room available — left unassigned, reported back
            }
            $plan[$room->instance->id][] = $passenger->id;
            $room->used++;
            $room->capacity = 1;
        }

        if ($sharedMembers->isEmpty()) {
            return $plan;
        }

        // Best fit: the room whose remaining capacity is the smallest value still >= group size
        // (avoids wasting a large room on a small group when a snugger one is free).
        $groupSize = $sharedMembers->count();
        $bestFit = $rooms->filter(fn (object $r) => ($r->capacity - $r->used) >= $groupSize)
            ->sortBy(fn (object $r) => $r->capacity - $r->used)
            ->first();

        if ($bestFit) {
            $plan[$bestFit->instance->id] = array_merge(
                $plan[$bestFit->instance->id] ?? [],
                $sharedMembers->pluck('id')->all()
            );
            $bestFit->used += $groupSize;

            return $plan;
        }

        // No single room fits the whole group — split across the emptiest rooms first, to keep
        // as many members together as capacity allows.
        $toPlace = $sharedMembers->values();
        while ($toPlace->isNotEmpty()) {
            $room = $rooms->filter(fn (object $r) => ($r->capacity - $r->used) > 0)
                ->sortByDesc(fn (object $r) => $r->capacity - $r->used)
                ->first();

            if (!$room) {
                break; // truly out of capacity — whatever's left in $toPlace stays unassigned
            }

            $slot = $room->capacity - $room->used;
            $chunk = $toPlace->splice(0, $slot);

            $plan[$room->instance->id] = array_merge(
                $plan[$room->instance->id] ?? [],
                $chunk->pluck('id')->all()
            );
            $room->used += $chunk->count();
        }

        return $plan;
    }

    /**
     * @return Collection<int, Passenger>
     */
    private function findUnassignedPassengers(Collection $roomTypeIds): Collection
    {
        $bookingIds = BookingRoomSelection::whereIn('room_type_id', $roomTypeIds)
            ->pluck('booking_id')
            ->unique();

        return Passenger::whereIn('booking_id', $bookingIds)
            ->whereDoesntHave('roomAssignment')
            ->whereHas('booking', fn ($q) => $q->where('booking_status', '!=', BookingStatus::Cancelled))
            ->get();
    }

    /**
     * Whether $passenger's booking selected 'single' occupancy for the given room type — the
     * paid single-supplement, not a new rule. When $roomTypeId is null (auto-assign group
     * planning, before a specific room is chosen), checks whether the booking selected 'single'
     * for ANY room type — the exact room comes later, but the passenger already needs isolation.
     */
    private function requiresSingleOccupancy(?Passenger $passenger, ?int $roomTypeId): bool
    {
        if (!$passenger) {
            return false;
        }

        $query = BookingRoomSelection::where('booking_id', $passenger->booking_id)
            ->where('occupancy_type', OccupancyTypeEnum::Single->value);

        if ($roomTypeId !== null) {
            $query->where('room_type_id', $roomTypeId);
        }

        return $query->exists();
    }
}
