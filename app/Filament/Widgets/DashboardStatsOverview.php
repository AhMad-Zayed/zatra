<?php

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use App\Models\Booking;
use App\Models\TripInstance;
use App\Models\Passenger;
use App\Enums\BookingStatus;
use App\Enums\TripStatusEnum;
use Filament\Facades\Filament;

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

        // 1. Total Revenue Collected (Cents / 100)
        $rawRevenue = Booking::where('tenant_id', $tenantId)
            ->where('booking_status', '!=', BookingStatus::Cancelled)
            ->sum('total_paid');
        $formattedRevenue = number_format($rawRevenue / 100, 2);

        // 2. Outstanding Balances
        $rawOutstanding = Booking::where('tenant_id', $tenantId)
            ->where('booking_status', '!=', BookingStatus::Cancelled)
            ->sum('balance_due');
        $formattedOutstanding = number_format($rawOutstanding / 100, 2);

        // 3. Occupancy Rate (Operational Metric)
        $activeTrips = TripInstance::where('tenant_id', $tenantId)
            ->whereIn('status', [TripStatusEnum::Active, TripStatusEnum::InProgress])
            ->get();
            
        $totalCapacity = $activeTrips->sum('available_seats');
        $activeTripIds = $activeTrips->pluck('id');
        
        $bookedPassengers = Passenger::whereHas('booking', function($query) use ($activeTripIds) {
            $query->whereIn('trip_instance_id', $activeTripIds)
                  ->whereIn('booking_status', [BookingStatus::Confirmed, BookingStatus::Pending]);
        })->count();

        $occupancyRate = $totalCapacity > 0 ? round(($bookedPassengers / $totalCapacity) * 100, 1) : 0;

        return [
            Stat::make('الإيرادات المحصلة (Total Revenue)', $formattedRevenue . ' SAR')
                ->description('إجمالي المبالغ المستلمة فعلياً')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('success'),
                
            Stat::make('الديون المعلقة (Outstanding)', $formattedOutstanding . ' SAR')
                ->description('مبالغ قيد الانتظار أو الدفع النقدي المتأخر')
                ->descriptionIcon('heroicon-m-clock')
                ->color('danger'),
                
            Stat::make('نسبة الإشغال (Occupancy Rate)', $occupancyRate . '%')
                ->description("الركاب: {$bookedPassengers} / السعة: {$totalCapacity}")
                ->descriptionIcon('heroicon-m-users')
                ->color('warning'),
        ];
    }
}
