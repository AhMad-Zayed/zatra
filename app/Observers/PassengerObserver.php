<?php

namespace App\Observers;

use App\Models\Passenger;
use App\Services\BookingService;
use App\Services\InventoryService;

class PassengerObserver
{
    protected BookingService $bookingService;
    protected InventoryService $inventoryService;

    public function __construct(BookingService $bookingService, InventoryService $inventoryService)
    {
        $this->bookingService = $bookingService;
        $this->inventoryService = $inventoryService;
    }

    public function created(Passenger $passenger): void
    {
        if ($passenger->booking && $passenger->tripPassengerCategory?->requires_seat) {
            $this->inventoryService->adjustForPassengerChange($passenger->booking, 1);
        }
        
        if ($passenger->booking) {
            $this->bookingService->recalculateTotals($passenger->booking);
        }
    }

    public function deleted(Passenger $passenger): void
    {
        if ($passenger->booking && $passenger->tripPassengerCategory?->requires_seat) {
            $this->inventoryService->adjustForPassengerChange($passenger->booking, -1);
        }
        
        if ($passenger->booking) {
            $this->bookingService->recalculateTotals($passenger->booking);
        }
    }
}
