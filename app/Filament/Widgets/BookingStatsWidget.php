<?php

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use App\Models\Booking;
use App\Enums\BookingStatus;
use Filament\Facades\Filament;

class BookingStatsWidget extends BaseWidget
{
    protected static ?int $sort = 1;

    public static function canView(): bool
    {
        return auth()->user()?->hasAnyRole(['agency_admin', 'accountant']) ?? false;
    }

    protected function getStats(): array
    {
        $tenantId = Filament::getTenant()?->id ?? auth()->user()->tenant_id;

        // 1. Total Bookings Today
        $bookingsToday = Booking::where('tenant_id', $tenantId)
            ->whereDate('created_at', now()->toDateString())
            ->where('booking_status', '!=', BookingStatus::Cancelled)
            ->count();

        // 2. Total Revenue Collected (cents to actual currency)
        $rawRevenue = Booking::where('tenant_id', $tenantId)
            ->where('booking_status', '!=', BookingStatus::Cancelled)
            ->sum('total_paid');
        $formattedRevenue = number_format($rawRevenue / 100, 2);

        // 3. Outstanding Balances (Debts)
        $rawOutstanding = Booking::where('tenant_id', $tenantId)
            ->where('booking_status', '!=', BookingStatus::Cancelled)
            ->sum('balance_due');
        $formattedOutstanding = number_format($rawOutstanding / 100, 2);

        return [
            Stat::make('حجوزات اليوم (Total Bookings Today)', $bookingsToday)
                ->description('الحجوزات التي تمت اليوم')
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color('primary'),

            Stat::make('الإيرادات المحصلة (Total Revenue Collected)', '$' . $formattedRevenue)
                ->description('إجمالي المبالغ المستلمة فعلياً')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('success'),
                
            Stat::make('الديون المعلقة (Outstanding Balances)', '$' . $formattedOutstanding)
                ->description('مبالغ قيد الانتظار أو الدفع النقدي المتأخر')
                ->descriptionIcon('heroicon-m-clock')
                ->color('danger'),
        ];
    }
}
