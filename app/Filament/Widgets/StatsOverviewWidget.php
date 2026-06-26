<?php

namespace App\Filament\Widgets;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\Passenger;
use App\Enums\BookingStatus;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverviewWidget extends BaseWidget
{
    protected function getStats(): array
    {
        $tenant = Filament::getTenant();

        if (!$tenant) {
            return [];
        }

        // 1. Total Booking Value (non-cancelled)
        $totalSales = (float) Booking::where('tenant_id', $tenant->id)
            ->where('status', '!=', BookingStatus::CANCELLED->value)
            ->sum('total_amount');

        // 2. Total Collected Payments
        $totalCollected = (float) Payment::where('tenant_id', $tenant->id)
            ->sum('amount');

        // 3. Remaining / Pending Balances
        $pendingBalance = max(0.00, $totalSales - $totalCollected);

        // 4. Active passengers on future trips
        $activePassengers = Passenger::whereHas('booking', function ($q) use ($tenant) {
            $q->where('tenant_id', $tenant->id)
              ->where('status', '!=', BookingStatus::CANCELLED->value)
              ->whereHas('tripInstance', function ($ti) {
                  $ti->where('start_date', '>=', now()->startOfDay());
              });
        })->count();

        return [
            Stat::make('إجمالي المبيعات (الحجوزات)', '$' . number_format($totalSales, 2))
                ->description('شاملة جميع الحجوزات النشطة وغير الملغية')
                ->descriptionIcon('heroicon-m-shopping-bag')
                ->color('success'),

            Stat::make('المبالغ المحصلة (الخزينة)', '$' . number_format($totalCollected, 2))
                ->description('إجمالي المبالغ النقدية والمحولة المستلمة فعلياً')
                ->descriptionIcon('heroicon-m-credit-card')
                ->color('success'),

            Stat::make('الذمم المالية المستحقة (المتبقية)', '$' . number_format($pendingBalance, 2))
                ->description('المبالغ المتبقية المطلوب تحصيلها من الزبائن')
                ->descriptionIcon('heroicon-m-clock')
                ->color($pendingBalance > 0 ? 'warning' : 'success'),

            Stat::make('عدد المسافرين النشطين', $activePassengers)
                ->description('المسافرين المسجلين في رحلات قادمة')
                ->descriptionIcon('heroicon-m-users')
                ->color('primary'),
        ];
    }
}
