<?php

namespace App\Filament\Support;

use App\Models\TripInstance;
use App\Models\TripPassengerCategory;
use App\Models\WaitingList;
use App\Services\WaitlistConversionService;
use Filament\Forms;
use Filament\Notifications\Notification;

/**
 * Shared form schema + submit handler for the "convert waiting-list entry to a booking on a
 * different trip" action, reused identically by WaitingListResource's table and
 * TripInstanceResource's WaitingListsRelationManager (same pattern as send_link_now being
 * duplicated across both surfaces) so the two entry points can't drift apart.
 */
class WaitlistConversionForm
{
    /**
     * @return array<\Filament\Forms\Components\Component>
     */
    public static function schema(): array
    {
        return [
            Forms\Components\Select::make('destination_trip_instance_id')
                ->label('الرحلة الجديدة')
                ->options(fn (WaitingList $record) => TripInstance::with('tripTemplate')
                    // Explicit tenant scope from the start (not copied from the pre-fix
                    // WaitingListResource picker pattern) -- scoped to the waiting list's own
                    // tenant, matching the CRITICAL HOTFIX fix applied elsewhere in this ticket.
                    ->where('tenant_id', $record->tenant_id)
                    ->where('start_date', '>=', now())
                    ->get()
                    ->mapWithKeys(fn ($t) => [
                        $t->id => trim(($t->tripTemplate?->title ?? '—') . ' — ' . $t->start_date?->format('Y-m-d') . ' (' . $t->remaining_seats . ' مقعد متاح)'),
                    ]))
                ->searchable()
                ->required()
                ->live(),

            Forms\Components\Repeater::make('allocation')
                ->label('توزيع المقاعد حسب الفئة')
                ->schema([
                    Forms\Components\Select::make('category_id')
                        ->label('الفئة')
                        ->options(fn (Forms\Get $get) => TripPassengerCategory::where('trip_instance_id', $get('../../destination_trip_instance_id'))
                            ->pluck('name', 'id'))
                        ->required(),
                    Forms\Components\TextInput::make('count')
                        ->label('العدد')
                        ->numeric()
                        ->minValue(1)
                        ->default(1)
                        ->required(),
                ])
                ->columns(2)
                ->defaultItems(1)
                ->visible(fn (Forms\Get $get) => (bool) $get('destination_trip_instance_id'))
                ->required(),
        ];
    }

    public static function handle(array $data, WaitingList $record): void
    {
        $destination = TripInstance::find($data['destination_trip_instance_id'] ?? null);

        if (!$destination) {
            Notification::make()->danger()->title('يرجى اختيار الرحلة الجديدة')->send();

            return;
        }

        try {
            $booking = app(WaitlistConversionService::class)->convertToBooking(
                $record,
                $destination,
                $data['allocation'] ?? [],
                auth()->id()
            );

            Notification::make()
                ->success()
                ->title('تم تحويل طلب الانتظار إلى حجز رقم ' . $booking->pnr)
                ->send();
        } catch (\Throwable $e) {
            Notification::make()->danger()->title('تعذر التحويل')->body($e->getMessage())->send();
        }
    }
}
