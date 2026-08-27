<?php

namespace App\Filament\Pages;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Passenger;
use App\Models\TripInstance;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Collection;

/**
 * "Bookings by Trip" nested browse screen — a pure read-only overview tool, NOT a replacement
 * for BookingResource's own table (which keeps all its edit/cancel/transfer/payment actions
 * untouched; this screen has none of them). Follows the AssignRooms/AssignBuses convention
 * already established in this codebase for bespoke, non-CRUD Filament pages: a thin data-fetch
 * layer here, all rendering in a dedicated Blade view.
 *
 * Filament's native Table component cannot express 3 simultaneously-nested accordion levels
 * (Filament\Tables\Grouping\Group supports exactly one active grouping dimension at a time, not
 * nested sub-groups) -- confirmed by reading the Group class directly during Phase 0 -- so this
 * is a bespoke Blade view with explicit expand/collapse state, not an InteractsWithTable page.
 *
 * Desktop-only by design (per the approved ticket) -- no responsive breakpoints needed.
 */
class BookingsByTrip extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationGroup = 'الحجوزات';

    protected static ?string $navigationLabel = 'عرض الحجوزات حسب الرحلة';

    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.pages.bookings-by-trip';

    protected static ?string $title = 'عرض الحجوزات حسب الرحلة';

    public ?array $data = [];

    /** @var array<int, bool> */
    public array $expandedTrips = [];

    /** @var array<int, bool> */
    public array $expandedBookings = [];

    public function mount(): void
    {
        $this->form->fill([
            'trip_name' => null,
            'date_from' => null,
            'date_to' => null,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('trip_name')
                    ->label('بحث حسب اسم الرحلة')
                    ->placeholder('اكتب اسم الرحلة...')
                    ->live(debounce: 300),
                // Filament's own DatePicker with native(false) -- the exact same component
                // Reports Center's HasReportFilters trait already uses for every date-range
                // filter in this admin panel -- renders its own localized picker UI instead of
                // the browser's raw native <input type="date"> (mm/dd/yyyy in an en-US browser,
                // the specific thing flagged in the mockup review).
                DatePicker::make('date_from')
                    ->label('من تاريخ')
                    ->native(false)
                    ->live(),
                DatePicker::make('date_to')
                    ->label('إلى تاريخ')
                    ->native(false)
                    ->live(),
            ])
            ->statePath('data')
            ->columns(3);
    }

    public function toggleTrip(int $tripId): void
    {
        $this->expandedTrips[$tripId] = !($this->expandedTrips[$tripId] ?? false);
    }

    public function toggleBooking(int $bookingId): void
    {
        $this->expandedBookings[$bookingId] = !($this->expandedBookings[$bookingId] ?? false);
    }

    /**
     * @return Collection<int, TripInstance>
     */
    public function getTrips(): Collection
    {
        $query = TripInstance::query()
            ->where('tenant_id', Filament::getTenant()?->id)
            ->with([
                'tripTemplate',
                'bookings' => fn ($q) => $q
                    ->where('booking_status', '!=', BookingStatus::Cancelled->value)
                    ->with([
                        'customer',
                        'passengers' => fn ($pq) => $pq->orderBy('id'),
                        'passengers.tripPassengerCategory',
                        'passengers.roomAssignment.roomInstance',
                    ]),
            ]);

        if ($tripName = trim((string) ($this->data['trip_name'] ?? ''))) {
            $query->whereHas('tripTemplate', fn ($q) => $q->where('title', 'like', "%{$tripName}%"));
        }

        if ($dateFrom = $this->data['date_from'] ?? null) {
            $query->whereDate('start_date', '>=', $dateFrom);
        }

        if ($dateTo = $this->data['date_to'] ?? null) {
            $query->whereDate('start_date', '<=', $dateTo);
        }

        return $query->orderBy('start_date')->get();
    }

    /**
     * Trip-wide aggregate financial badge, per the confirmed rule: a simple booking-count-based
     * bucket for a quick-glance overview badge (not a precise financial statement), consistent
     * with how badges are used elsewhere in this app (Reports Center's own bucketed badges, not
     * exact-amount gradients). Reuses PaymentStatus's own success/warning/danger semantics —
     * there is no existing trip-level aggregate field to read directly (only each Booking has
     * its own payment_status), so this is new-but-tiny display logic, not a pure reuse.
     */
    public function tripFinancialColor(Collection $bookings): string
    {
        $total = $bookings->count();

        if ($total === 0) {
            return 'gray';
        }

        $paidCount = $bookings->where('payment_status', PaymentStatus::Paid)->count();

        return match (true) {
            $paidCount === $total => 'success',
            $paidCount === 0 => 'danger',
            default => 'warning',
        };
    }

    public function tripFinancialLabel(Collection $bookings): string
    {
        return match ($this->tripFinancialColor($bookings)) {
            'success' => 'مكتمل الدفع',
            'danger' => 'غير مدفوع',
            'warning' => 'دفع جزئي',
            default => '—',
        };
    }

    /**
     * "٤/٤ مكتملة" -- Passenger.requirements_complete is already persisted by
     * RequirementValidationService at booking/update time, read directly here with zero service
     * call needed for display.
     */
    public function documentsCompletionFraction(Booking $booking): string
    {
        $total = $booking->passengers->count();
        $complete = $booking->passengers->where('requirements_complete', true)->count();

        return $this->toArabicDigits("{$complete}/{$total}") . ' مكتملة';
    }

    /**
     * No dedicated "is this passenger the booking's customer" flag exists anywhere on Passenger
     * (confirmed during Phase 0) -- the first passenger row created for a booking (by id) is
     * used as the heuristic, since every booking-creation entry point in this app (CreateBookingService,
     * PhoneBookingPage, etc.) places the lead/primary traveler first in passengersData. An
     * approximation, not a guaranteed signal -- logged to docs/TECH_DEBT.md.
     */
    public function isBookingOwner(Passenger $passenger, Booking $booking): bool
    {
        $firstPassengerId = $booking->passengers->min('id');

        return $firstPassengerId !== null && $passenger->id === $firstPassengerId;
    }

    public function seatOrRoomDisplay(Passenger $passenger): string
    {
        $parts = array_filter([
            $passenger->seat_number ? "مقعد {$passenger->seat_number}" : null,
            $passenger->roomAssignment?->roomInstance?->room_number
                ? "غرفة {$passenger->roomAssignment->roomInstance->room_number}"
                : null,
        ]);

        return $parts ? implode(' / ', $parts) : '—';
    }

    private function toArabicDigits(string $value): string
    {
        return strtr($value, ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤', '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩']);
    }
}
