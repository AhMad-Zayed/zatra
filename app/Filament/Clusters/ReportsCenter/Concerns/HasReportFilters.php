<?php

namespace App\Filament\Clusters\ReportsCenter\Concerns;

use App\Enums\TripTypeEnum;
use App\Models\TripInstance;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Reports Center shared filter shell (date range / trip / trip_type), per Phase 0 Section C —
 * every report reuses this same form UI and just supplies its own small closure for how to
 * apply each filter to its own base query (whose root model differs per report: Passenger for
 * Report 1, Booking for Report 2, etc.), rather than each report reimplementing the filter
 * fields from scratch. A future report added to this cluster reuses this trait the same way.
 */
trait HasReportFilters
{
    /**
     * @param  callable(Builder, ?string, ?string): Builder  $applyDateRange  ($query, $dateFrom, $dateTo)
     * @param  callable(Builder, ?int): Builder  $applyTripInstance  ($query, $tripInstanceId)
     * @param  callable(Builder, ?string): Builder  $applyTripType  ($query, $tripType)
     * @return array<int, Filter|SelectFilter>
     */
    protected function reportFilters(
        callable $applyDateRange,
        callable $applyTripInstance,
        callable $applyTripType,
        ?string $defaultDateFrom = null,
        ?string $defaultDateTo = null,
    ): array {
        return [
            Filter::make('date_range')
                ->label('الفترة الزمنية')
                ->form([
                    DatePicker::make('date_from')
                        ->label('من تاريخ')
                        ->default($defaultDateFrom)
                        ->native(false),
                    DatePicker::make('date_to')
                        ->label('إلى تاريخ')
                        ->default($defaultDateTo)
                        ->native(false),
                ])
                ->query(fn (Builder $query, array $data): Builder => $applyDateRange(
                    $query,
                    $this->normalizeReportFilterDate($data['date_from'] ?? null),
                    $this->normalizeReportFilterDate($data['date_to'] ?? null),
                ))
                ->indicateUsing(function (array $data): array {
                    $indicators = [];
                    if ($from = $this->normalizeReportFilterDate($data['date_from'] ?? null)) {
                        $indicators[] = 'من: ' . $from;
                    }
                    if ($to = $this->normalizeReportFilterDate($data['date_to'] ?? null)) {
                        $indicators[] = 'إلى: ' . $to;
                    }

                    return $indicators;
                }),

            SelectFilter::make('trip_instance_id')
                ->label('الرحلة')
                ->options(fn () => TripInstance::query()
                    ->where('tenant_id', Filament::getTenant()?->id)
                    ->where('status', 'active')
                    ->with('tripTemplate')
                    ->orderBy('start_date')
                    ->get()
                    ->mapWithKeys(fn (TripInstance $t) => [
                        $t->id => trim(($t->tripTemplate?->title ?? '—') . ' — ' . $t->start_date?->format('Y-m-d')),
                    ]))
                ->searchable()
                ->query(fn (Builder $query, array $data): Builder => $applyTripInstance($query, $data['value'] ?? null)),

            SelectFilter::make('trip_type')
                ->label('نوع الرحلة')
                ->options(TripTypeEnum::class)
                ->query(fn (Builder $query, array $data): Builder => $applyTripType($query, $data['value'] ?? null)),
        ];
    }

    /**
     * Filament's DatePicker, with ->native(false), always stringifies its state through
     * Carbon::createFromFormat('Y-m-d', ...) -- which, given a date-only format, fills the
     * unspecified time portion with the current wall-clock time rather than midnight -- then
     * casts that to a string, producing a full "Y-m-d H:i:s" timestamp for what's meant to be a
     * plain date. Every report using this trait's date_range filter then feeds that value into
     * whereDate($column, '>=', $from) as a raw string: DATE($column) is compared lexicographically
     * against the timestamp, and for a same-day boundary "2026-08-29" >= "2026-08-29 21:49:30" is
     * false, silently excluding today's own rows any time other than exactly midnight. Re-parsing
     * here and keeping only the date guarantees every $applyDateRange closure (and the filter
     * indicator) always sees a pure start-of-day boundary, regardless of what Filament's hydration
     * produced -- this is the single choke point every report's date filter passes through, so
     * fixing it here fixes it for all of them at once, current and future.
     */
    private function normalizeReportFilterDate(?string $date): ?string
    {
        if (blank($date)) {
            return null;
        }

        return Carbon::parse($date)->toDateString();
    }
}
