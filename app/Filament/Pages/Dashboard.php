<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;

/**
 * Extends Filament's base Dashboard purely to give it a navigationGroup and a top-of-sidebar
 * sort position — the underlying page/widget-grid behavior is untouched.
 *
 * Replaces the former split between this page (admin/accountant) and the separate
 * StaffDashboard page (agent/staff): both audiences now land here, with each widget deciding
 * for itself whether to render via its own canView() (see QuickActionsWidget, StaffOverviewWidget,
 * DashboardStatsOverview, RevenueChart).
 */
class Dashboard extends BaseDashboard
{
    protected static ?string $navigationGroup = 'الرئيسية';

    protected static ?string $navigationLabel = 'الرئيسية';

    protected static ?int $navigationSort = -10;
}
