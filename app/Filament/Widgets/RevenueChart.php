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

    public static function canView(): bool
    {
        return auth()->user()?->hasAnyRole(['agency_admin', 'accountant']) ?? false;
    }

    // Distinct colors cycled per currency series — extend if a tenant ever runs more than
    // this many concurrent currencies.
    private const SERIES_COLORS = [
        ['border' => '#ca8a04', 'background' => 'rgba(202, 138, 4, 0.1)'],   // Zatara Gold
        ['border' => '#2563eb', 'background' => 'rgba(37, 99, 235, 0.1)'],   // Blue
        ['border' => '#16a34a', 'background' => 'rgba(22, 163, 74, 0.1)'],   // Green
        ['border' => '#dc2626', 'background' => 'rgba(220, 38, 38, 0.1)'],   // Red
        ['border' => '#9333ea', 'background' => 'rgba(147, 51, 234, 0.1)'],  // Purple
    ];

    protected function getData(): array
    {
        $tenantId = Filament::getTenant()?->id ?? auth()->user()->tenant_id;
        $currentYear = Carbon::now()->year;

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
            ->whereYear('created_at', $currentYear)
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
}
