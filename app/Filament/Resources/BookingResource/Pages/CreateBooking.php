<?php

namespace App\Filament\Resources\BookingResource\Pages;

use App\Filament\Resources\BookingResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use App\Services\CreateBookingService;
use App\Exceptions\InventoryExhaustedException;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;

class CreateBooking extends CreateRecord
{
    protected static string $resource = BookingResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // 1. Silently inject the Audit Trail (Creator Admin ID)
        $data['user_id'] = auth()->id();
        
        // 2. Silently inject the Tenant ID (Admin context)
        $data['tenant_id'] = \Filament\Facades\Filament::getTenant()?->id ?? auth()->user()->tenants()->first()->id;

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        $service = new CreateBookingService();
        
        // Format Passengers Payload
        $passengersData = [];
        if (isset($data['passengers'])) {
            foreach ($data['passengers'] as $p) {
                $passengersData[] = [
                    'trip_pricing_tier_id' => $p['trip_pricing_tier_id'],
                    'dynamic_data' => $p['dynamic_data'] ?? null,
                ];
            }
        }
        $data['passengersData'] = $passengersData;
        
        // Format Addons Payload
        $addonsData = [];
        if (isset($data['bookingAddons'])) {
            foreach ($data['bookingAddons'] as $a) {
                $addonsData[] = [
                    'trip_addon_id' => $a['trip_addon_id'],
                    'quantity' => $a['quantity'],
                ];
            }
        }
        $data['addonsData'] = $addonsData;

        try {
            // Pass the UNIFIED payload array to the refactored Service
            $booking = $service->execute($data);
            
            if (isset($data['booking_status'])) {
                $booking->update(['booking_status' => $data['booking_status']]);
            }
            
            return $booking;
            
        } catch (InventoryExhaustedException $e) {
            Notification::make()
                ->danger()
                ->title('فشل الحجز (عذراً نفذت الكمية)')
                ->body($e->getMessage())
                ->send();
                
            $this->halt();
        }
    }
}
