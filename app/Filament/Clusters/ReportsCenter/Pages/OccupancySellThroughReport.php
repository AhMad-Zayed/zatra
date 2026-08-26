<?php

namespace App\Filament\Clusters\ReportsCenter\Pages;

use App\Enums\BookingStatus;
use App\Filament\Clusters\ReportsCenter;
use App\Filament\Clusters\ReportsCenter\Concerns\HasReportFilters;
use App\Models\TripInstance;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use pxlrbt\FilamentExcel\Actions\Tables\ExportAction;
use pxlrbt\FilamentExcel\Exports\ExcelExport;

/**
 * Reports Center Ticket 3, Report 3 (Occupancy & Sell-Through). Read-only: only ever SELECTs.
 *
 * Capacity is read directly from TripInstance.available_seats — the single authoritative value
 * Bus/Fleet Ticket 2 already established (kept in sync with the sum of active bus assignments
 * when a trip has any, otherwise the plain manually-entered value). No special-casing needed
 * here for either source; this report is simply another reader of that one column.
 *
 * "Fills over time": the average number of days between a booking's created_at and the trip's
 * start_date, across that trip's active (non-cancelled) bookings — a per-trip lead-time signal
 * showing whether a trip tends to fill early or late, computed in PHP from eager-loaded
 * bookings (this app's data volume makes that simpler and just as reliable as a SQL subquery
 * here, matching the pattern already used for Report 1's missing-item detail).
 *
 * No chart widget: the ticket left this explicitly optional ("your call, not required for
 * correctness"). The occupancy % column is sortable and immediately scannable on its own, and
 * adding a widget class would be scope the report doesn't need to earn its value — skipped.
 */
class OccupancySellThroughReport extends Page implements HasTable
{
    use HasReportFilters;
    use InteractsWithTable;

    protected static ?string $cluster = ReportsCenter::class;

    protected static string $view = 'filament.clusters.reports-center.pages.occupancy-sell-through-report';

    protected static ?string $navigationLabel = 'الإشغال ونسبة البيع';

    protected static ?string $title = 'تقرير الإشغال ونسبة البيع';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                TripInstance::query()
                    ->where('tenant_id', Filament::getTenant()?->id)
                    ->withCount(['activePassengers as seats_booked'])
                    ->with([
                        'tripTemplate',
                        'bookings' => fn ($q) => $q->where('booking_status', '!=', BookingStatus::Cancelled->value),
                    ])
            )
            ->defaultSort('start_date', 'asc')
            ->columns([
                TextColumn::make('tripTemplate.title')->label('الرحلة'),
                TextColumn::make('start_date')->label('تاريخ المغادرة')->date(),
                TextColumn::make('available_seats')
                    ->label('السعة')
                    ->placeholder('غير محدود'),
                TextColumn::make('seats_booked')
                    ->label('المقاعد المحجوزة')
                    ->sortable(),
                TextColumn::make('occupancy')
                    ->label('نسبة الإشغال')
                    ->state(function (TripInstance $record): string {
                        if (!$record->available_seats) {
                            return '—';
                        }

                        $pct = min(100, round(($record->seats_booked / $record->available_seats) * 100, 1));

                        return "{$pct}%";
                    })
                    ->badge()
                    ->color(function (TripInstance $record): string {
                        if (!$record->available_seats) {
                            return 'gray';
                        }
                        $pct = ($record->seats_booked / $record->available_seats) * 100;

                        return match (true) {
                            $pct >= 80 => 'success',
                            $pct >= 40 => 'warning',
                            default => 'danger',
                        };
                    }),
                TextColumn::make('avg_lead_time')
                    ->label('متوسط مدة الحجز المسبق')
                    ->state(function (TripInstance $record): string {
                        if ($record->bookings->isEmpty()) {
                            return '—';
                        }

                        $avgDays = $record->bookings
                            ->map(fn ($booking) => $booking->created_at->diffInDays($record->start_date))
                            ->avg();

                        return round($avgDays) . ' يوم قبل المغادرة';
                    }),
            ])
            ->filters($this->reportFilters(
                applyDateRange: fn (Builder $q, ?string $from, ?string $to) => $q
                    ->when($from, fn (Builder $q2) => $q2->whereDate('start_date', '>=', $from))
                    ->when($to, fn (Builder $q2) => $q2->whereDate('start_date', '<=', $to)),
                applyTripInstance: fn (Builder $q, ?int $tripInstanceId) => $q
                    ->when($tripInstanceId, fn (Builder $q2) => $q2->where('id', $tripInstanceId)),
                applyTripType: fn (Builder $q, ?string $tripType) => $q
                    ->when($tripType, fn (Builder $q2) => $q2->whereHas(
                        'tripTemplate',
                        fn (Builder $q3) => $q3->where('trip_type', $tripType)
                    )),
            ))
            ->headerActions([
                ExportAction::make()
                    ->label('تصدير Excel')
                    ->exports([
                        ExcelExport::make()
                            ->fromTable()
                            ->withFilename('تقرير-الإشغال-ونسبة-البيع-' . now()->format('Y-m-d')),
                    ]),
            ])
            ->emptyStateHeading('لا توجد رحلات')
            ->emptyStateDescription('لا توجد رحلات تستوفي شروط التصفية الحالية.')
            ->emptyStateIcon('heroicon-o-chart-bar')
            ->poll(null);
    }
}
