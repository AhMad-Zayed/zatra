<?php

namespace App\Filament\Clusters\ReportsCenter\Concerns;

use App\Enums\TripTypeEnum;
use App\Models\TripInstance;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;

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
                    $data['date_from'] ?? null,
                    $data['date_to'] ?? null,
                ))
                ->indicateUsing(function (array $data): array {
                    $indicators = [];
                    if ($data['date_from'] ?? null) {
                        $indicators[] = 'من: ' . $data['date_from'];
                    }
                    if ($data['date_to'] ?? null) {
                        $indicators[] = 'إلى: ' . $data['date_to'];
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
}
