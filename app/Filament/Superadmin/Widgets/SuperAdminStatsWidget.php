<?php

namespace App\Filament\Superadmin\Widgets;

use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use App\Models\Tenant;
use App\Models\Booking;
use App\Models\Customer;

class SuperAdminStatsWidget extends BaseWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        return [
            Stat::make('Total Active Agencies', Tenant::withoutGlobalScopes()->where('is_active', true)->count())
                ->description('الوكالات المفعلة حالياً على المنصة')
                ->descriptionIcon('heroicon-m-building-office-2')
                ->color('success'),
            Stat::make('Total Platform Bookings', Booking::withoutGlobalScopes()->count())
                ->description('عدد الحجوزات عبر جميع الوكالات')
                ->descriptionIcon('heroicon-m-ticket')
                ->color('primary'),
            Stat::make('Total Platform Customers', Customer::withoutGlobalScopes()->count())
                ->description('إجمالي عدد العملاء المسجلين')
                ->descriptionIcon('heroicon-m-users')
                ->color('info'),
        ];
    }
}
