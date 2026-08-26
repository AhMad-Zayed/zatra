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
 * Reports Center Ticket 2 — the distinct, filterable RefundPending view: who's owed money back
 * and since when. This is what makes the payment_status = RefundPending liability (tracked by
 * BookingService::cancelBooking() since an earlier ticket) actually visible/actionable, instead
 * of only living in the database. Appears as its own tab in the cluster's shared sub-navigation,
 * alongside OutstandingBalanceReport.
 *
 * Amount shown is labeled "paid, candidate for refund" rather than a definitive "amount owed" —
 * cancelBooking() tracks the refund liability purely via payment_status, without writing a
 * dedicated refundable-amount field, and grand_total is only overridden to a cancellation fee
 * when an admin explicitly sets one (otherwise it's untouched by cancellation). Rather than
 * inventing a "total_paid - fee" formula that would be silently wrong whenever no fee applies
 * (grand_total would still reflect the pre-cancellation total, not 0), this report shows
 * total_paid (what was actually collected) alongside grand_total (its current, possibly
 * fee-overridden total) so staff can judge the real refundable amount themselves — an honest
 * reflection of what this app's data model actually tracks, not a guessed number.
 */
class RefundPendingReport extends Page implements HasTable
{
    use HasReportFilters;
    use InteractsWithTable;

    protected static ?string $cluster = ReportsCenter::class;

    protected static string $view = 'filament.clusters.reports-center.pages.refund-pending-report';

    protected static ?string $navigationLabel = 'بانتظار الاسترداد';

    protected static ?string $title = 'تقرير المبالغ بانتظار الاسترداد';

    /**
     * @return Collection<int, array{currency: string, total: float}>
     */
    public function currencyTotals(): Collection
    {
        return (clone $this->getFilteredTableQuery())
            ->reorder()
            ->select('currency')
            ->selectRaw('SUM(total_paid) as total_cents')
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
                    ->where('payment_status', PaymentStatus::RefundPending->value)
                    ->with(['customer', 'tripInstance.tripTemplate', 'payments'])
            )
            ->groups([
                Group::make('currency')->label('العملة'),
            ])
            ->defaultGroup('currency')
            ->defaultSort('updated_at', 'asc')
            ->columns([
                TextColumn::make('pnr')->label('رقم الحجز'),
                TextColumn::make('customer.name')->label('العميل'),
                TextColumn::make('tripInstance.tripTemplate.title')->label('الرحلة'),
                TextColumn::make('currency')->label('العملة'),
                TextColumn::make('total_paid')
                    ->label('المبلغ المدفوع (مرشح للاسترداد)')
                    ->money(fn (Booking $record) => $record->currency),
                TextColumn::make('grand_total')
                    ->label('الإجمالي الحالي')
                    ->money(fn (Booking $record) => $record->currency)
                    ->toggleable(),
                TextColumn::make('updated_at')
                    ->label('منذ')
                    ->state(fn (Booking $record) => (int) $record->updated_at->diffInDays(now()))
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
                            ->withFilename('تقرير-بانتظار-الاسترداد-' . now()->format('Y-m-d')),
                    ]),
            ])
            ->emptyStateHeading('لا توجد مبالغ بانتظار الاسترداد')
            ->emptyStateDescription('لا توجد حجوزات ملغاة بها مبالغ مدفوعة بانتظار الاسترداد ضمن التصفية الحالية.')
            ->emptyStateIcon('heroicon-o-check-circle')
            ->poll(null);
    }
}
