<?php

namespace App\Filament\Widgets;

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
}
