<?php

namespace App\Filament\Widgets;

use Filament\Widgets\ChartWidget;
use App\Models\Payment;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class RevenueChart extends ChartWidget
{
    protected static ?string $heading = 'التدفقات النقدية السنوية (Annual Cash Flow)';
    protected int | string | array $columnSpan = 'full';
    protected static ?int $sort = 2;

    public static function canView(): bool
    {
        return auth()->user()?->hasAnyRole(['agency_admin', 'accountant']) ?? false;
    }

    protected function getData(): array
    {
        $tenantId = Filament::getTenant()?->id ?? auth()->user()->tenant_id;
        $currentYear = Carbon::now()->year;

        // Group payments by month, dividing by 100 to handle Cents
        $monthlyRevenues = Payment::select(
                DB::raw('sum(amount) as total'),
                DB::raw('MONTH(created_at) as month')
            )
            ->where('tenant_id', $tenantId)
            ->whereYear('created_at', $currentYear)
            ->groupBy('month')
            ->get()
            ->pluck('total', 'month')
            ->toArray();

        // Ensure all 12 months exist in the array (fill missing with 0)
        $data = [];
        for ($i = 1; $i <= 12; $i++) {
            $data[] = isset($monthlyRevenues[$i]) ? ($monthlyRevenues[$i] / 100) : 0;
        }

        return [
            'datasets' => [
                [
                    'label' => 'الإيرادات المحصلة (SAR)',
                    'data' => $data,
                    'borderColor' => '#ca8a04', // Zatara Gold
                    'backgroundColor' => 'rgba(202, 138, 4, 0.1)',
                    'fill' => true,
                    'tension' => 0.4,
                ],
            ],
            'labels' => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
