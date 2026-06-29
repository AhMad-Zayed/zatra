<?php

namespace App\Filament\Resources\BookingResource\Pages;

use App\Filament\Resources\BookingResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListBookings extends ListRecords
{
    protected static string $resource = BookingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => \Filament\Resources\Components\Tab::make('الكل (All)'),
            'cancellation_requests' => \Filament\Resources\Components\Tab::make('طلبات الإلغاء (Cancellation Requests)')
                ->badge(\App\Models\Booking::whereNotNull('cancellation_requested_at')
                    ->where('booking_status', '!=', \App\Enums\BookingStatus::Cancelled)
                    ->count())
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (\Illuminate\Database\Eloquent\Builder $query) => $query->whereNotNull('cancellation_requested_at')
                    ->where('booking_status', '!=', \App\Enums\BookingStatus::Cancelled)),
        ];
    }
}
