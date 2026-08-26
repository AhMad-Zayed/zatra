<?php

namespace App\Filament\Resources\TripInstanceResource\Pages;

use App\Exceptions\RoomCapacityExceededException;
use App\Filament\Resources\TripInstanceResource;
use App\Models\Passenger;
use App\Models\RoomInstance;
use App\Models\TripStayLegHotelOption;
use App\Services\RoomAssignmentService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Collection;

/**
 * Hotel/Rooming redesign Ticket 3 — the staff-facing drag-and-drop rooming board. A thin
 * Livewire consumer of RoomAssignmentService: every read/write goes through the service, this
 * page only wires it to the UI and to Filament notifications.
 */
class AssignRooms extends Page
{
    use InteractsWithRecord;

    protected static string $resource = TripInstanceResource::class;

    protected static string $view = 'filament.resources.trip-instance-resource.pages.assign-rooms';

    protected static ?string $title = 'تخصيص الغرف';

    public ?int $selectedHotelOptionId = null;

    public function mount(int | string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->selectedHotelOptionId = $this->hotelOptions()->first()?->id;
    }

    /**
     * Every hotel option actually in use on this trip instance (has at least one booking that
     * selected a room type under it) — options nobody booked don't need a board.
     *
     * @return Collection<int, TripStayLegHotelOption>
     */
    public function hotelOptions(): Collection
    {
        return TripStayLegHotelOption::query()
            ->whereHas('tripStayLeg', fn ($q) => $q->where('trip_instance_id', $this->record->id))
            ->whereHas('roomTypes.bookingRoomSelections')
            ->with(['hotel', 'tripStayLeg'])
            ->get();
    }

    public function selectedHotelOption(): ?TripStayLegHotelOption
    {
        if (!$this->selectedHotelOptionId) {
            return null;
        }

        return TripStayLegHotelOption::with(['hotel', 'tripStayLeg'])->find($this->selectedHotelOptionId);
    }

    public function selectHotelOption(int $hotelOptionId): void
    {
        $this->selectedHotelOptionId = $hotelOptionId;
    }

    /**
     * @return array{unassigned: Collection, roomTypes: Collection}
     */
    public function boardData(): array
    {
        $option = $this->selectedHotelOption();

        if (!$option) {
            return ['unassigned' => collect(), 'roomTypes' => collect()];
        }

        app(RoomAssignmentService::class)->ensureRoomInstancesExistForHotelOption($option);

        return app(RoomAssignmentService::class)->getBoardData($option);
    }

    public function dropPassenger(int $passengerId, int $roomInstanceId): void
    {
        $passenger = Passenger::findOrFail($passengerId);
        $roomInstance = RoomInstance::findOrFail($roomInstanceId);

        // Tenant-isolation guard: refuse to place a passenger from a different tenant's booking,
        // matching every other resource's standard scoping, even though the route/panel tenant
        // middleware already prevents reaching this page for another tenant's trip.
        if ($passenger->tenant_id !== $this->record->tenant_id || $roomInstance->tenant_id !== $this->record->tenant_id) {
            abort(403);
        }

        try {
            app(RoomAssignmentService::class)->assignPassenger($passenger, $roomInstance, auth()->user());
        } catch (RoomCapacityExceededException $e) {
            Notification::make()
                ->danger()
                ->title('تعذر التخصيص')
                ->body($e->getMessage())
                ->send();
        }
    }

    public function removeFromRoom(int $passengerId): void
    {
        $passenger = Passenger::findOrFail($passengerId);

        if ($passenger->tenant_id !== $this->record->tenant_id) {
            abort(403);
        }

        app(RoomAssignmentService::class)->unassignPassenger($passenger);
    }

    public function runAutoAssign(): void
    {
        $option = $this->selectedHotelOption();

        if (!$option) {
            return;
        }

        $result = app(RoomAssignmentService::class)->autoAssign($option, auth()->user());

        if ($result['unassigned']->isEmpty()) {
            Notification::make()
                ->success()
                ->title("تم تخصيص {$result['assigned']} راكب تلقائياً")
                ->send();

            return;
        }

        Notification::make()
            ->warning()
            ->title("تم تخصيص {$result['assigned']} راكب — تعذر تخصيص " . $result['unassigned']->count())
            ->body('بانتظار التخصيص اليدوي: ' . $result['unassigned']->pluck('display_name')->implode('، '))
            ->persistent()
            ->send();
    }

    public function roomingListUrl(): ?string
    {
        $option = $this->selectedHotelOption();

        return $option ? route('hotel-option.rooming-list', $option) : null;
    }
}
