<?php

namespace App\Filament\Clusters\ReportsCenter\Pages;

use App\Enums\PaymentStatus;
use App\Filament\Clusters\ReportsCenter;
use App\Filament\Clusters\ReportsCenter\Concerns\HasReportFilters;
use App\Models\Booking;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use pxlrbt\FilamentExcel\Actions\Tables\ExportAction;
use pxlrbt\FilamentExcel\Exports\ExcelExport;

/**
 * Reports Center Ticket 2, Report 2 (Outstanding Balance). Read-only: only ever SELECTs.
 *
 * Grouped/filterable by currency throughout — the same currency-mixing flaw already fixed once
 * in RevenueChart (summing unlike currencies into one number) is deliberately never repeated
 * here. Rather than trusting a table-wide column summarizer (whose exact grouped-vs-overall
 * behavior isn't worth staking a financial-correctness guarantee on), the per-currency totals
 * bar above the table is computed by this page's own explicit groupBy('currency') query,
 * re-applied against the table's live filter state via getFilteredTableQuery() so it always
 * agrees with what's actually shown below it.
 */
class OutstandingBalanceReport extends Page implements HasTable
{
    use HasReportFilters;
    use InteractsWithTable;

    protected static ?string $cluster = ReportsCenter::class;

    protected static string $view = 'filament.clusters.reports-center.pages.outstanding-balance-report';

    protected static ?string $navigationLabel = 'الأرصدة المستحقة';

    protected static ?string $title = 'تقرير الأرصدة المستحقة';

    /**
     * @return Collection<int, array{currency: string, total: float}>
     */
    public function currencyTotals(): Collection
    {
        return (clone $this->getFilteredTableQuery())
            ->reorder()
            ->select('currency')
            ->selectRaw('SUM(balance_due) as total_cents')
            ->groupBy('currency')
            ->get()
            ->map(fn ($row) => ['currency' => $row->currency, 'total' => $row->total_cents / 100]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Booking::query()
                    ->where('tenant_id', Filament::getTenant()?->id)
                    ->where(fn (Builder $q) => $q
                        ->where('balance_due', '>', 0)
                        ->orWhereIn('payment_status', [
                            PaymentStatus::PartiallyPaid->value,
                            PaymentStatus::RefundPending->value,
                        ]))
                    ->with(['customer', 'tripInstance.tripTemplate', 'payments'])
            )
            ->groups([
                Group::make('currency')->label('العملة'),
            ])
            ->defaultGroup('currency')
            ->defaultSort('created_at', 'asc')
            ->columns([
                TextColumn::make('pnr')->label('رقم الحجز'),
                TextColumn::make('customer.name')->label('العميل'),
                TextColumn::make('tripInstance.tripTemplate.title')->label('الرحلة'),
                TextColumn::make('currency')->label('العملة'),
                TextColumn::make('balance_due')
                    ->label('المبلغ المستحق')
                    ->money(fn (Booking $record) => $record->currency),
                TextColumn::make('payment_status')->label('حالة الدفع')->badge(),
                TextColumn::make('created_at')
                    ->label('أيام الاستحقاق')
                    ->state(fn (Booking $record) => (int) $record->created_at->diffInDays(now()))
                    ->suffix(' يوم')
                    ->sortable(),
            ])
            ->filters($this->reportFilters(
                applyDateRange: fn (Builder $q, ?string $from, ?string $to) => $q
                    ->when($from, fn (Builder $q2) => $q2->whereDate('created_at', '>=', $from))
                    ->when($to, fn (Builder $q2) => $q2->whereDate('created_at', '<=', $to)),
                applyTripInstance: fn (Builder $q, ?int $tripInstanceId) => $q
                    ->when($tripInstanceId, fn (Builder $q2) => $q2->where('trip_instance_id', $tripInstanceId)),
                applyTripType: fn (Builder $q, ?string $tripType) => $q
                    ->when($tripType, fn (Builder $q2) => $q2->whereHas(
                        'tripInstance.tripTemplate',
                        fn (Builder $q3) => $q3->where('trip_type', $tripType)
                    )),
            ))
            ->actions([
                Action::make('payment_history')
                    ->label('سجل الدفعات')
                    ->icon('heroicon-o-clock')
                    ->color('gray')
                    ->modalHeading('سجل الدفعات')
                    ->modalContent(fn (Booking $record) => view(
                        'filament.clusters.reports-center.partials.payment-history',
                        ['payments' => $record->payments]
                    ))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('إغلاق'),
            ])
            ->headerActions([
                ExportAction::make()
                    ->label('تصدير Excel')
                    ->exports([
                        ExcelExport::make()
                            ->fromTable()
                            ->withFilename('تقرير-الأرصدة-المستحقة-' . now()->format('Y-m-d')),
                    ]),
            ])
            ->emptyStateHeading('لا توجد أرصدة مستحقة')
            ->emptyStateDescription('لا توجد حجوزات تستوفي شروط التصفية الحالية.')
            ->emptyStateIcon('heroicon-o-check-circle')
            ->poll(null);
    }
}
