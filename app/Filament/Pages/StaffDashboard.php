<?php

namespace App\Filament\Pages;

use App\Models\Booking;
use App\Models\TripInstance;
use App\Enums\BookingStatus;
use App\Enums\TripStatusEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;

class StaffDashboard extends Page
{
    protected static ?string $navigationIcon    = 'heroicon-o-home';
    protected static ?string $navigationGroup   = null; // Top-level, no group
    protected static ?int    $navigationSort     = -10; // Appears at the very top of the sidebar
    protected static ?string $navigationLabel   = 'الرئيسية';
    protected static ?string $title             = 'لوحة التحكم';
    protected static string  $view              = 'filament.pages.staff-dashboard';

    /**
     * Only show to non-admin users (agents / staff).
     * Admins can see the default Filament Dashboard with analytics.
     */
    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(['agent', 'staff', 'agency_admin']) ?? false;
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
