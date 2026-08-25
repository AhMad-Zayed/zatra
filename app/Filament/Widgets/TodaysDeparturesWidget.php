<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;
use App\Models\TripInstance;
use App\Enums\TripStatusEnum;
use App\Enums\BookingStatus;
use Illuminate\Support\Facades\DB;
use Filament\Facades\Filament;

/**
 * HIGH-006: Today's Departures Widget — was completely missing from the dashboard.
 * Shows every trip scheduled for today with real-time fill rates and unpaid booking alerts.
 *
 * FINANCIAL NOTE: grand_total and total_paid are stored as integer cents.
 * The ->sum() calls here bypass MoneyCast and return raw cents. Divide by 100 for display.
 */
class TodaysDeparturesWidget extends Widget
{
    protected static string $view = 'filament.widgets.todays-departures';

    protected int | string | array $columnSpan = 'full';

    protected static ?int $sort = 3;

    public function getTodaysDepartures(): \Illuminate\Support\Collection
    {
        $tenantId = Filament::getTenant()?->id ?? auth()->user()->tenant_id;

        return TripInstance::with(['tripTemplate', 'bookings'])
            ->where('tenant_id', $tenantId)
            ->whereDate('start_date', today())
            ->where('status', TripStatusEnum::Active)
            ->get()
            ->map(function ($instance) {
                $totalCapacity = $instance->available_seats ?? 0;
                $remaining = $instance->remaining_seats;
                $usedSeats = max(0, $totalCapacity - $remaining);

                $confirmedCount = $instance->bookings()
                    ->whereIn('booking_status', [
                        BookingStatus::Confirmed->value,
                        BookingStatus::ConfirmedPartial->value,
                    ])
                    ->withCount('passengers')
                    ->get()
                    ->sum('passengers_count');

                $unpaidCount = $instance->bookings()
                    ->where('balance_due', '>', 0)
                    ->where('booking_status', '!=', BookingStatus::Cancelled->value)
                    ->count();

                $fillRate = $totalCapacity > 0
                    ? round(($usedSeats / $totalCapacity) * 100)
                    : 0;

                return [
                    'id'           => $instance->id,
                    'title'        => $instance->tripTemplate?->title ?? '—',
                    'time'         => $instance->start_date->format('h:i A'),
                    'confirmed'    => $confirmedCount,
                    'capacity'     => $totalCapacity,
                    'fill_rate'    => $fillRate,
                    'unpaid_count' => $unpaidCount,
                    'manifest_url' => route('trip-instance.manifest', $instance),
                ];
            });
    }
}
