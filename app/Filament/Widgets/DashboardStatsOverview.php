<?php

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use App\Models\Booking;
use App\Models\TripInstance;
use App\Models\Passenger;
use App\Models\WaitingList;
use App\Enums\BookingStatus;
use App\Enums\WaitingListStatusEnum;
use App\Enums\TripStatusEnum;
use Filament\Facades\Filament;

/**
 * CRIT-001: Financial columns are stored as integer cents (after migration 2026_06_28_000002_convert_financials_to_integers).
 * ->sum() bypasses MoneyCast and returns raw cents. Dividing by 100 gives the correct SAR value.
 * DO NOT remove the /100 divisions below.
 *
 * CRIT-001 (merged): BookingStatsWidget was deleted and merged here to eliminate duplicate stats and
 * conflicting ->sort = 1 values. All unique stats are consolidated in this single widget.
 */
class DashboardStatsOverview extends BaseWidget
{
    protected static ?int $sort = 1;

    public static function canView(): bool
    {
        return auth()->user()?->hasAnyRole(['agency_admin', 'accountant']) ?? false;
    }

    protected function getStats(): array
    {
        $tenantId = Filament::getTenant()?->id ?? auth()->user()->tenant_id;

        // 1. Bookings Today (from merged BookingStatsWidget)
        $bookingsToday = Booking::where('tenant_id', $tenantId)
            ->whereDate('created_at', now()->toDateString())
            ->where('booking_status', '!=', BookingStatus::Cancelled)
            ->count();

        // 2. Total Revenue Collected (cents/100 → SAR)
        // NOTE: ->sum() bypasses MoneyCast, returns raw integer cents. /100 = SAR value.
        $rawRevenue = (float) Booking::where('tenant_id', $tenantId)
            ->where('booking_status', '!=', BookingStatus::Cancelled)
            ->sum('total_paid');
        $formattedRevenue = number_format($rawRevenue / 100, 2);

        // 3. Outstanding Balances (cents/100 → SAR)
        $rawOutstanding = (float) Booking::where('tenant_id', $tenantId)
            ->where('booking_status', '!=', BookingStatus::Cancelled)
            ->sum('balance_due');
        $formattedOutstanding = number_format($rawOutstanding / 100, 2);

        // 4. Occupancy Rate (Operational Metric)
        $activeTripsQuery = TripInstance::where('tenant_id', $tenantId)
            ->whereIn('status', [TripStatusEnum::Active, TripStatusEnum::InProgress]);

        $totalCapacity = (int) $activeTripsQuery->sum('available_seats');
        $activeTripIds = $activeTripsQuery->pluck('id');

        $bookedPassengers = 0;
        if ($activeTripIds->isNotEmpty()) {
            $bookedPassengers = Passenger::whereHas('booking', function ($query) use ($activeTripIds) {
                $query->whereIn('trip_instance_id', $activeTripIds)
                      ->whereIn('booking_status', [BookingStatus::Confirmed, BookingStatus::Pending]);
            })->count();
        }

        $occupancyRate = $totalCapacity > 0 ? round(($bookedPassengers / $totalCapacity) * 100, 1) : 0;

        // 5. Waitlist Count (Pending & Notified)
        $waitlistCount = WaitingList::where('tenant_id', $tenantId)
            ->whereIn('status', [WaitingListStatusEnum::Pending, WaitingListStatusEnum::Notified])
            ->count();

        return [
            Stat::make('حجوزات اليوم', $bookingsToday)
                ->description('الحجوزات التي تمت اليوم')
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color('primary'),

            Stat::make('إجمالي الإيرادات', $formattedRevenue . ' SAR')
                ->description('إجمالي المبالغ المستلمة فعلياً')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('success'),

            Stat::make('الأرصدة المعلقة', $formattedOutstanding . ' SAR')
                ->description('مبالغ قيد الانتظار أو الدفع النقدي المتأخر')
                ->descriptionIcon('heroicon-m-clock')
                ->color('danger'),

            Stat::make('نسبة إشغال المقاعد', $occupancyRate . '%')
                ->description("الركاب: {$bookedPassengers} / السعة: {$totalCapacity}")
                ->descriptionIcon('heroicon-m-users')
                ->color('warning'),

            Stat::make('طلبات قائمة الانتظار', $waitlistCount)
                ->description('طلبات بانتظار توفر مقاعد')
                ->descriptionIcon('heroicon-m-queue-list')
                ->color('info'),
        ];
    }
}
