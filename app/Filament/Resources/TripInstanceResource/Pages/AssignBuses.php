<?php

namespace App\Filament\Resources\TripInstanceResource\Pages;

use App\Enums\BusOwnershipTypeEnum;
use App\Exceptions\BusCapacityExceededException;
use App\Filament\Resources\TripInstanceResource;
use App\Models\Passenger;
use App\Models\TripBusAssignment;
use App\Models\Vehicle;
use App\Services\BusSeatAssignmentService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Database\Eloquent\Collection;

/**
 * Bus/Fleet redesign Ticket 1 (CRUD skeleton: add/edit/remove TripBusAssignment rows) + Ticket 3
 * (drag-and-drop seat assignment, built directly on top of the same page — mirroring how
 * AssignRooms grew from a data skeleton into the full room-assignment board).
 */
class AssignBuses extends Page
{
    use InteractsWithRecord;

    protected static string $resource = TripInstanceResource::class;

    protected static string $view = 'filament.resources.trip-instance-resource.pages.assign-buses';

    protected static ?string $title = 'تخصيص الحافلات';

    public function mount(int | string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    /**
     * @return Collection<int, TripBusAssignment>
     */
    public function buses(): Collection
    {
        return $this->record->tripBusAssignments()
            ->with(['vehicle', 'driverStaff', 'guideStaff'])
            ->get();
    }

    public function totalCapacity(): int
    {
        return $this->buses()->sum('capacity');
    }

    /**
     * Bus/Fleet redesign Ticket 3 — the drag-and-drop board data: unassigned passenger pool +
     * each bus with its current occupants.
     *
     * @return array{unassigned: Collection<int, Passenger>, buses: Collection<int, TripBusAssignment>}
     */
    public function boardData(): array
    {
        return app(BusSeatAssignmentService::class)->getBoardData($this->record);
    }

    public function dropPassenger(int $passengerId, int $busId): void
    {
        $passenger = Passenger::findOrFail($passengerId);
        $bus = $this->findBus($busId);

        // Tenant-isolation guard: refuse to place a passenger from a different tenant's booking,
        // matching AssignRooms' identical guard, even though the route/panel tenant middleware
        // already prevents reaching this page for another tenant's trip.
        if ($passenger->tenant_id !== $this->record->tenant_id) {
            abort(403);
        }

        try {
            app(BusSeatAssignmentService::class)->assignPassengerToBus($passenger, $bus, auth()->user());
        } catch (BusCapacityExceededException $e) {
            Notification::make()
                ->danger()
                ->title('تعذر التخصيص')
                ->body($e->getMessage())
                ->send();
        }
    }

    public function removeFromBus(int $passengerId): void
    {
        $passenger = Passenger::findOrFail($passengerId);

        if ($passenger->tenant_id !== $this->record->tenant_id) {
            abort(403);
        }

        app(BusSeatAssignmentService::class)->unassignPassenger($passenger);
    }

    public function runAutoAssign(): void
    {
        $result = app(BusSeatAssignmentService::class)->autoAssign($this->record, auth()->user());

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

    protected function getHeaderActions(): array
    {
        return [
            $this->addBusAction(),
        ];
    }

    public function addBusAction(): Action
    {
        return Action::make('addBus')
            ->label('إضافة حافلة')
            ->icon('heroicon-o-plus')
            ->color('primary')
            ->form($this->busFormSchema())
            ->action(function (array $data) {
                $data['tenant_id'] = $this->record->tenant_id;
                $data['trip_instance_id'] = $this->record->id;
                $data['sort_order'] = $this->record->tripBusAssignments()->max('sort_order') + 1;

                TripBusAssignment::create($data);

                Notification::make()->success()->title('تمت إضافة الحافلة')->send();
            });
    }

    public function editBusAction(): Action
    {
        return Action::make('editBus')
            ->label('تعديل')
            ->icon('heroicon-o-pencil')
            ->color('gray')
            ->form($this->busFormSchema())
            ->fillForm(function (array $arguments) {
                return $this->findBus($arguments['id'])?->toArray() ?? [];
            })
            ->action(function (array $data, array $arguments) {
                $bus = $this->findBus($arguments['id']);

                if (!$bus) {
                    return;
                }

                $bus->update($data);

                Notification::make()->success()->title('تم تحديث بيانات الحافلة')->send();
            });
    }

    public function deleteBusAction(): Action
    {
        return Action::make('deleteBus')
            ->label('حذف')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('حذف الحافلة')
            ->modalDescription('سيتم حذف هذه الحافلة من الرحلة. هل أنت متأكد؟')
            ->action(function (array $arguments) {
                $this->findBus($arguments['id'])?->delete();

                Notification::make()->success()->title('تم حذف الحافلة')->send();
            });
    }

    private function findBus(int $id): ?TripBusAssignment
    {
        $bus = TripBusAssignment::find($id);

        // Tenant-isolation guard: refuse to touch a bus assignment belonging to a different
        // trip/tenant, matching AssignRooms' identical guard for room assignments — the route/
        // panel tenant middleware already prevents reaching this page for another tenant's trip,
        // this is the same belt-and-suspenders check as every other resource in this app.
        if (!$bus || $bus->trip_instance_id !== $this->record->id || $bus->tenant_id !== $this->record->tenant_id) {
            abort(403);
        }

        return $bus;
    }

    /**
     * @return array<int, Forms\Components\Component>
     */
    private function busFormSchema(): array
    {
        return [
            Forms\Components\Radio::make('ownership_type')
                ->label('نوع الملكية')
                ->options(BusOwnershipTypeEnum::class)
                ->default(BusOwnershipTypeEnum::Owned->value)
                ->inline()
                ->live()
                ->required(),

            Forms\Components\Select::make('vehicle_id')
                ->label('المركبة')
                ->options(fn () => Vehicle::where('tenant_id', Filament::getTenant()?->id)
                    ->where('is_active', true)
                    ->pluck('plate_number', 'id'))
                ->searchable()
                ->live()
                ->afterStateUpdated(function (Set $set, ?string $state) {
                    if ($state) {
                        $set('capacity', Vehicle::find($state)?->default_capacity);
                    }
                })
                ->visible(fn (Get $get) => $get('ownership_type') === BusOwnershipTypeEnum::Owned->value)
                ->required(fn (Get $get) => $get('ownership_type') === BusOwnershipTypeEnum::Owned->value),

            Forms\Components\TextInput::make('rented_supplier_name')
                ->label('اسم شركة التأجير')
                ->maxLength(255)
                ->visible(fn (Get $get) => $get('ownership_type') === BusOwnershipTypeEnum::Rented->value)
                ->required(fn (Get $get) => $get('ownership_type') === BusOwnershipTypeEnum::Rented->value),

            Forms\Components\TextInput::make('rented_plate_number')
                ->label('رقم لوحة الحافلة المستأجرة (اختياري)')
                ->maxLength(255)
                ->visible(fn (Get $get) => $get('ownership_type') === BusOwnershipTypeEnum::Rented->value),

            Forms\Components\TextInput::make('capacity')
                ->label('السعة (عدد المقاعد)')
                ->numeric()
                ->integer()
                ->minValue(1)
                ->required(),

            Forms\Components\Section::make('السائق')
                ->schema(TripBusAssignment::personFieldsSchema('driver', 'نوع السائق'))
                ->columns(2),

            Forms\Components\Section::make('المرشد')
                ->schema(TripBusAssignment::personFieldsSchema('guide', 'نوع المرشد'))
                ->columns(2),
        ];
    }
}
