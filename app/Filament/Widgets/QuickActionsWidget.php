<?php

namespace App\Filament\Widgets;

use App\Models\TripInstance;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

/**
 * Visible to every role — shortcuts to the most common actions, not gated behind canView().
 * Replaces the "Quick Actions" grid that used to live only on the now-retired StaffDashboard.
 */
class QuickActionsWidget extends Widget
{
    protected static string $view = 'filament.widgets.quick-actions';

    protected int | string | array $columnSpan = 'full';

    protected static ?int $sort = 0;

    /**
     * Admin panel UX audit, quick win: a brand-new tenant's dashboard (revenue chart, occupancy
     * stat, today's trips) renders essentially blank with no guidance until real trips/bookings
     * exist. Gates a one-line "get started" nudge above the tiles, pointing at trip creation —
     * the actual first step every other widget's data depends on.
     */
    public function isFreshTenant(): bool
    {
        return ! TripInstance::where('tenant_id', Filament::getTenant()?->id)->exists();
    }
}
