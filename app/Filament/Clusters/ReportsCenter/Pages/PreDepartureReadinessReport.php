<?php

namespace App\Filament\Clusters\ReportsCenter\Pages;

use App\Enums\BookingStatus;
use App\Filament\Clusters\ReportsCenter;
use App\Filament\Clusters\ReportsCenter\Concerns\HasReportFilters;
use App\Models\Passenger;
use App\Services\RequirementValidationService;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use pxlrbt\FilamentExcel\Actions\Tables\ExportAction;
use pxlrbt\FilamentExcel\Exports\ExcelExport;

/**
 * Reports Center Ticket 1, Report 1 (highest priority per the stakeholder) — every passenger
 * with requirements_complete = false on a trip departing within the filtered window, grouped by
 * trip, soonest departure first. The "what's missing" detail reuses
 * RequirementValidationService::findMissingRequirements() exactly as designed in Phase 0 —
 * no reimplementation of that logic here.
 *
 * Read-only: this page only ever SELECTs. Zero writes, zero calls into any guardrail-protected
 * service.
 */
class PreDepartureReadinessReport extends Page implements HasTable
{
    use HasReportFilters;
    use InteractsWithTable;

    protected static ?string $cluster = ReportsCenter::class;

    protected static string $view = 'filament.clusters.reports-center.pages.pre-departure-readiness-report';

    protected static ?string $navigationLabel = 'جاهزية ما قبل السفر';

    protected static ?string $title = 'تقرير جاهزية ما قبل السفر';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                // Explicit joins (rather than relation-path sort/group) so ordering by real
                // departure date and grouping by trip are both guaranteed correct SQL, not
                // dependent on Filament's automatic relationship-column resolution holding up
                // across two levels (booking -> tripInstance).
                Passenger::query()
                    ->select('passengers.*')
                    ->selectRaw('bookings.trip_instance_id as report_trip_instance_id')
                    ->selectRaw('trip_instances.start_date as report_trip_start_date')
                    ->join('bookings', 'bookings.id', '=', 'passengers.booking_id')
                    ->join('trip_instances', 'trip_instances.id', '=', 'bookings.trip_instance_id')
                    ->where('passengers.tenant_id', Filament::getTenant()?->id)
                    ->where('passengers.requirements_complete', false)
                    ->where('bookings.booking_status', '!=', BookingStatus::Cancelled->value)
                    ->orderBy('trip_instances.start_date', 'asc')
                    ->with(['booking.tripInstance.tripTemplate', 'booking.customer', 'media'])
            )
            ->groups([
                Group::make('report_trip_instance_id')
                    ->label('الرحلة')
                    ->getTitleFromRecordUsing(fn (Passenger $record): string => trim(
                        ($record->booking?->tripInstance?->tripTemplate?->title ?? '—')
                        . ' — ' . ($record->booking?->tripInstance?->start_date?->format('Y-m-d') ?? '—')
                    )),
            ])
            ->defaultGroup('report_trip_instance_id')
            ->columns([
                TextColumn::make('display_name')
                    ->label('الراكب'),
                TextColumn::make('booking.pnr')
                    ->label('رقم الحجز'),
                TextColumn::make('booking.customer.name')
                    ->label('العميل'),
                TextColumn::make('booking.customer.phone')
                    ->label('الهاتف'),
                TextColumn::make('report_trip_start_date')
                    ->label('تاريخ المغادرة')
                    ->date(),
                TextColumn::make('missing_items')
                    ->label('الناقص')
                    ->state(fn (Passenger $record) => $this->missingItemLabels($record))
                    ->badge()
                    ->color('danger')
                    ->separator('، '),
            ])
            ->filters($this->reportFilters(
                applyDateRange: fn (Builder $q, ?string $from, ?string $to) => $q
                    ->when($from, fn (Builder $q2) => $q2->whereDate('trip_instances.start_date', '>=', $from))
                    ->when($to, fn (Builder $q2) => $q2->whereDate('trip_instances.start_date', '<=', $to)),
                applyTripInstance: fn (Builder $q, ?int $tripInstanceId) => $q
                    ->when($tripInstanceId, fn (Builder $q2) => $q2->where('bookings.trip_instance_id', $tripInstanceId)),
                applyTripType: fn (Builder $q, ?string $tripType) => $q
                    ->when($tripType, fn (Builder $q2) => $q2->whereHas(
                        'booking.tripInstance.tripTemplate',
                        fn (Builder $q3) => $q3->where('trip_type', $tripType)
                    )),
                defaultDateFrom: now()->toDateString(),
                defaultDateTo: now()->addDays(14)->toDateString(),
            ))
            ->headerActions([
                ExportAction::make()
                    ->label('تصدير Excel')
                    ->exports([
                        ExcelExport::make()
                            ->fromTable()
                            ->withFilename('تقرير-جاهزية-ما-قبل-السفر-' . now()->format('Y-m-d')),
                    ]),
            ])
            ->emptyStateHeading('لا يوجد ركاب بانتظار إكمال المتطلبات')
            ->emptyStateDescription('جميع ركاب الرحلات القادمة ضمن الفترة المحددة أكملوا متطلباتهم.')
            ->emptyStateIcon('heroicon-o-check-circle')
            ->poll(null);
    }

    /**
     * @return array<int, string>
     */
    private function missingItemLabels(Passenger $passenger): array
    {
        $preset = $passenger->booking?->tripInstance?->tripTemplate?->requirementPreset;

        if (!$preset) {
            return [];
        }

        $missing = app(RequirementValidationService::class)->findMissingRequirements($preset, [[
            'document_number' => $passenger->document_number,
            'date_of_birth' => $passenger->date_of_birth?->format('Y-m-d'),
            'has_identity_document' => $passenger->relationLoaded('media')
                ? $passenger->getMedia('identity_documents')->isNotEmpty()
                : $passenger->hasMedia('identity_documents'),
        ]]);

        return array_values(array_unique(array_column($missing, 'label')));
    }
}
