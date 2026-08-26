<?php

namespace App\Filament\Clusters;

use Filament\Clusters\Cluster;

/**
 * Reports Center — a dedicated home for anything beyond basic dashboard KPIs, replacing the
 * scattered-widget approach. Each report is a page registered under
 * App\Filament\Clusters\ReportsCenter\Pages (auto-discovered via Filament's cluster
 * convention — see AdminPanelProvider's discoverClusters() call), sharing this cluster's nav
 * group/tab bar automatically. Adding a future report (once cost/margin tracking exists, per
 * the stakeholder's stated extensibility requirement) is just a new page class in that
 * directory — no restructuring of this class or the existing reports.
 */
class ReportsCenter extends Cluster
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?string $navigationGroup = 'التقارير';

    protected static ?string $navigationLabel = 'مركز التقارير';

    protected static ?string $title = 'مركز التقارير';

    protected static ?int $navigationSort = 1;
}
