<?php

namespace App\Filament\Widgets;

use Filament\Widgets\ChartWidget;
use App\Models\Payment;
use Filament\Facades\Filament;
use Carbon\Carbon;

class RevenueChart extends ChartWidget
{
    protected static ?string $heading = 'التدفقات النقدية السنوية';
    protected int | string | array $columnSpan = 'full';
    protected static ?int $sort = 2;

    public ?string $filter = 'this_year';

    public static function canView(): bool
    {
        return auth()->user()?->hasAnyRole(['agency_admin', 'accountant']) ?? false;
    }

    // Azure Horizon brand palette — primary Sapphire first (the common single-currency case),
    // then accent/semantic hues for any additional currency series. Fill opacity sits at the
    // spec's ~15-20% near-line range. Filament's ChartWidget passes getData() straight through
    // to Chart.js via a plain @js()-serialized array (see chart-widget.blade.php), so a dataset
    // can't hand Chart.js a canvas-gradient function from the server side — only a flat color.
    // This is the closest a config-only change gets to the reference "gradient fading to
    // transparent" look; a literal top-to-bottom fade would need a Chart.js plugin registered as
    // its own JS asset, which is out of scope for a widget config change.
    private const SERIES_COLORS = [
        ['border' => '#00355f', 'background' => 'rgba(0, 53, 95, 0.16)'],     // Sapphire (primary)
        ['border' => '#fe9835', 'background' => 'rgba(254, 152, 53, 0.16)'],  // Sunset Orange (accent)
        ['border' => '#059669', 'background' => 'rgba(5, 150, 105, 0.16)'],   // Emerald (success)
        ['border' => '#e11d48', 'background' => 'rgba(225, 29, 72, 0.16)'],   // Rose (danger)
        ['border' => '#005353', 'background' => 'rgba(0, 83, 83, 0.16)'],     // Deep teal
    ];

    protected function getFilters(): ?array
    {
        return [
            'this_year' => 'هذا العام',
            'last_year' => 'العام الماضي',
        ];
    }

    protected function getData(): array
    {
        $tenantId = Filament::getTenant()?->id ?? auth()->user()->tenant_id;
        $year = $this->filter === 'last_year' ? Carbon::now()->subYear()->year : Carbon::now()->year;

        // Bug fix: this used to sum Payment.amount across ALL currencies into a single line
        // labeled "(SAR)" regardless of what currency the money actually was — silently
        // mixing unlike currencies together the moment a tenant runs more than one (which the
        // schema explicitly supports: trip_templates/trip_instances/bookings/payments all have
        // their own `currency` column). Now grouped by currency, with one clearly-labeled
        // series/line per currency actually present in the data — no conversion between
        // currencies is performed or implied.
        //
        // Fetched as models and grouped/summed in PHP rather than a DB::raw() MONTH()/SUM()
        // query, for two reasons: (1) MONTH() is MySQL-specific (breaks on SQLite, used in
        // testing); (2) Collection::sum('amount') applies Payment's MoneyCast per row before
        // summing, avoiding the raw-integer-cents pitfall documented on DashboardStatsOverview
        // (a query-builder ->sum() bypasses casts entirely).
        $payments = Payment::where('tenant_id', $tenantId)
            ->whereYear('created_at', $year)
            ->get(['amount', 'currency', 'created_at']);

        $currencies = $payments->map(fn ($p) => $p->currency ?? 'USD')->unique()->sort()->values();

        $datasets = [];
        foreach ($currencies as $index => $currency) {
            $data = [];
            for ($month = 1; $month <= 12; $month++) {
                $data[] = (float) $payments
                    ->filter(fn ($p) => $p->created_at->month === $month && ($p->currency ?? 'USD') === $currency)
                    ->sum('amount');
            }

            $colors = self::SERIES_COLORS[$index % count(self::SERIES_COLORS)];

            $datasets[] = [
                'label' => "الإيرادات المحصلة ({$currency})",
                'data' => $data,
                'borderColor' => $colors['border'],
                'backgroundColor' => $colors['background'],
                'fill' => true,
                'tension' => 0.4,
                'pointBackgroundColor' => $colors['border'],
                'pointBorderColor' => '#ffffff',
                'pointBorderWidth' => 1.5,
                'pointRadius' => 3,
                'pointHoverRadius' => 5,
            ];
        }

        // No payments at all this year: show a flat zero line rather than an empty chart, but
        // without fabricating a currency guess for data that doesn't exist.
        if (empty($datasets)) {
            $datasets[] = [
                'label' => 'الإيرادات المحصلة',
                'data' => array_fill(0, 12, 0.0),
                'borderColor' => self::SERIES_COLORS[0]['border'],
                'backgroundColor' => self::SERIES_COLORS[0]['background'],
                'fill' => true,
                'tension' => 0.4,
                'pointBackgroundColor' => self::SERIES_COLORS[0]['border'],
                'pointBorderColor' => '#ffffff',
                'pointBorderWidth' => 1.5,
                'pointRadius' => 3,
                'pointHoverRadius' => 5,
            ];
        }

        return [
            'datasets' => $datasets,
            // LABEL-016: Arabic month names. CRIT-001: /100 is correct — DB stores integer cents.
            'labels' => ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'],
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getOptions(): array | \Filament\Support\RawJs | null
    {
        // Vertical gridlines are already off by default in Filament's chart JS
        // (scales.x.grid.display defaults to false) — only tidying the axis borders here so the
        // plot reads as a clean, mostly-open canvas per the reference design.
        return [
            'scales' => [
                'x' => [
                    'border' => ['display' => false],
                ],
                'y' => [
                    'border' => ['display' => false],
                    'beginAtZero' => true,
                ],
            ],
            'plugins' => [
                'legend' => [
                    'display' => true,
                ],
            ],
        ];
    }
}
