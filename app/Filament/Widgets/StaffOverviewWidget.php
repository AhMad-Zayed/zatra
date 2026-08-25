<?php

namespace App\Filament\Widgets;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\TripInstance;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

/**
 * Role-gated replacement for the retired StaffDashboard page: same stats-row + upcoming-trips
 * content agents/staff used to see on their own dashboard page, now a widget on the single
 * unified Dashboard (App\Filament\Pages\Dashboard) — canView() decides who sees it, per the
 * "same URL, same page, different visible content per role" decision. Read-only queries, same
 * shape as DashboardStatsOverview; no business logic touched.
 */
class StaffOverviewWidget extends Widget
{
    protected static string $view = 'filament.widgets.staff-overview';

    protected int | string | array $columnSpan = 'full';

    protected static ?int $sort = 1;

    public static function canView(): bool
    {
        // 'agent'/'staff' are not real role names anywhere in this app (RoleAndPermissionSeeder /
        // DatabaseSeeder both use 'booking_agent') — the retired StaffDashboard::canAccess() had
        // the same mismatch, so it was only ever reachable by agency_admin in practice, despite
        // its own docblock claiming "non-admin users (agents/staff)". Using the real role here.
        return auth()->user()?->hasRole('booking_agent') ?? false;
    }

    public function getStats(): array
    {
        $tenantId = Filament::getTenant()?->id;

        $bookingsToday = Booking::where('tenant_id', $tenantId)
            ->whereDate('created_at', today())
            ->where('booking_status', '!=', BookingStatus::Cancelled)
            ->count();

        $upcomingTrips = TripInstance::where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->where('start_date', '>=', today())
            ->where('start_date', '<=', today()->addDays(30))
            ->with('tripTemplate')
            ->orderBy('start_date')
            ->take(5)
            ->get();

        $cancellationRequests = Booking::where('tenant_id', $tenantId)
            ->whereNotNull('cancellation_requested_at')
            ->where('booking_status', '!=', BookingStatus::Cancelled)
            ->count();

        $pendingPayments = Booking::where('tenant_id', $tenantId)
            ->whereIn('booking_status', [BookingStatus::Pending, BookingStatus::ConfirmedPartial])
            ->count();

        return compact('bookingsToday', 'upcomingTrips', 'cancellationRequests', 'pendingPayments');
    }
}
