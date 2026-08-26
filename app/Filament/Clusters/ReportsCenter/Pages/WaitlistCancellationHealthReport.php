<?php

namespace App\Filament\Clusters\ReportsCenter\Pages;

use App\Enums\BookingStatus;
use App\Enums\WaitingListStatusEnum;
use App\Filament\Clusters\ReportsCenter;
use App\Filament\Clusters\ReportsCenter\Concerns\HasReportFilters;
use App\Models\TripTemplate;
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
 * Reports Center Ticket 3, Report 4 (Waitlist & Cancellation Health) — the final report,
 * closing out the Reports Center initiative. Read-only: only ever SELECTs.
 *
 * Grouped by TripTemplate (route/product history), per the confirmed decision — a single
 * cancelled trip instance isn't a meaningful "rate," a template's history across every instance
 * it has ever run is. Both metrics are computed in PHP from eager-loaded
 * tripInstances.bookings / tripInstances.waitingLists, the same approach already used for
 * Report 3's lead-time column, given this app's data volume makes a hand-rolled multi-level SQL
 * aggregate (TripTemplate -> TripInstance -> Booking / the waitlist pivot) both unnecessary and
 * more fragile than just eager-loading and reducing in PHP.
 *
 * Filter semantics decision (worth naming explicitly, since a template-grouped aggregate report
 * doesn't inherit the same filter meaning a per-instance report has): date_range/trip determine
 * which TEMPLATES qualify to appear at all (a template with zero matching trip instances is
 * excluded), but once a template qualifies, its cancellation/conversion rates are computed over
 * its FULL history, not narrowed to just the matching instances. Cancellation/waitlist health is
 * inherently a long-run per-route signal, not something staff would want artificially truncated
 * to a narrow window in the common case — narrowing WHICH routes are worth looking at, without
 * distorting the rate itself, is the more useful behavior here.
 */
class WaitlistCancellationHealthReport extends Page implements HasTable
{
    use HasReportFilters;
    use InteractsWithTable;

    protected static ?string $cluster = ReportsCenter::class;

    protected static string $view = 'filament.clusters.reports-center.pages.waitlist-cancellation-health-report';

    protected static ?string $navigationLabel = 'صحة الإلغاء والانتظار';

    protected static ?string $title = 'تقرير صحة الإلغاء وقوائم الانتظار';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                TripTemplate::query()
                    ->where('tenant_id', Filament::getTenant()?->id)
                    ->with(['tripInstances.bookings', 'tripInstances.waitingLists'])
            )
            ->defaultSort('title', 'asc')
            ->columns([
                TextColumn::make('title')->label('الرحلة'),
                TextColumn::make('total_bookings')
                    ->label('إجمالي الحجوزات')
                    ->state(fn (TripTemplate $record) => $record->tripInstances->flatMap->bookings->count()),
                TextColumn::make('cancellation_rate')
                    ->label('نسبة الإلغاء')
                    ->state(function (TripTemplate $record): string {
                        $bookings = $record->tripInstances->flatMap->bookings;
                        if ($bookings->isEmpty()) {
                            return '—';
                        }
                        $cancelled = $bookings->where('booking_status', BookingStatus::Cancelled)->count();

                        return round($cancelled / $bookings->count() * 100, 1) . '%';
                    })
                    ->badge()
                    ->color(function (TripTemplate $record): string {
                        $bookings = $record->tripInstances->flatMap->bookings;
                        if ($bookings->isEmpty()) {
                            return 'gray';
                        }
                        $rate = $bookings->where('booking_status', BookingStatus::Cancelled)->count() / $bookings->count() * 100;

                        return match (true) {
                            $rate <= 10 => 'success',
                            $rate <= 25 => 'warning',
                            default => 'danger',
                        };
                    }),
                TextColumn::make('waitlist_total')
                    ->label('إجمالي قائمة الانتظار')
                    ->state(fn (TripTemplate $record) => $record->tripInstances->flatMap->waitingLists->count()),
                TextColumn::make('conversion_rate')
                    ->label('نسبة تحويل قائمة الانتظار')
                    ->state(function (TripTemplate $record): string {
                        $waitlist = $record->tripInstances->flatMap->waitingLists;
                        $converted = $waitlist->where('status', WaitingListStatusEnum::Converted)->count();
                        $expired = $waitlist->where('status', WaitingListStatusEnum::Expired)->count();
                        $resolved = $converted + $expired;

                        if ($resolved === 0) {
                            return '—';
                        }

                        return round($converted / $resolved * 100, 1) . '%';
                    })
                    ->badge()
                    ->color(function (TripTemplate $record): string {
                        $waitlist = $record->tripInstances->flatMap->waitingLists;
                        $converted = $waitlist->where('status', WaitingListStatusEnum::Converted)->count();
                        $expired = $waitlist->where('status', WaitingListStatusEnum::Expired)->count();
                        $resolved = $converted + $expired;

                        if ($resolved === 0) {
                            return 'gray';
                        }

                        return $converted / $resolved * 100 >= 50 ? 'success' : 'warning';
                    }),
            ])
            ->filters($this->reportFilters(
                applyDateRange: fn (Builder $q, ?string $from, ?string $to) => $q
                    ->when($from, fn (Builder $q2) => $q2->whereHas(
                        'tripInstances',
                        fn (Builder $q3) => $q3->whereDate('start_date', '>=', $from)
                    ))
                    ->when($to, fn (Builder $q2) => $q2->whereHas(
                        'tripInstances',
                        fn (Builder $q3) => $q3->whereDate('start_date', '<=', $to)
                    )),
                applyTripInstance: fn (Builder $q, ?int $tripInstanceId) => $q
                    ->when($tripInstanceId, fn (Builder $q2) => $q2->whereHas(
                        'tripInstances',
                        fn (Builder $q3) => $q3->where('id', $tripInstanceId)
                    )),
                applyTripType: fn (Builder $q, ?string $tripType) => $q
                    ->when($tripType, fn (Builder $q2) => $q2->where('trip_type', $tripType)),
            ))
            ->headerActions([
                ExportAction::make()
                    ->label('تصدير Excel')
                    ->exports([
                        ExcelExport::make()
                            ->fromTable()
                            ->withFilename('تقرير-صحة-الإلغاء-والانتظار-' . now()->format('Y-m-d')),
                    ]),
            ])
            ->emptyStateHeading('لا توجد بيانات')
            ->emptyStateDescription('لا توجد برامج رحلات تستوفي شروط التصفية الحالية.')
            ->emptyStateIcon('heroicon-o-chart-bar')
            ->poll(null);
    }
}
