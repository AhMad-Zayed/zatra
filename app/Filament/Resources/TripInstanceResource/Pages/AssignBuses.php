<?php

namespace App\Filament\Resources\TripInstanceResource\Pages;

use App\Enums\BusOwnershipTypeEnum;
use App\Filament\Resources\TripInstanceResource;
use App\Models\TripBusAssignment;
use App\Models\Vehicle;
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
 * Bus/Fleet redesign Ticket 1 — CRUD skeleton only: add/edit/remove TripBusAssignment rows for
 * a trip instance. Deliberately NOT the drag-and-drop seat assignment UI (that's Ticket 3,
 * built on top of this same page later, mirroring how AssignRooms grew from a data skeleton).
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
