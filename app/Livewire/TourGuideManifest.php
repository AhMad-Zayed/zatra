<?php

namespace App\Livewire;

use Livewire\Component;
use App\Models\TripInstance;
use App\Models\Booking;
use App\Models\Passenger;
use Livewire\Attributes\Layout;

#[Layout('components.layouts.storefront')]
class TourGuideManifest extends Component
{
    public $uuid;
    public TripInstance $trip;
    
    public $searchTerm = '';
    
    // For manual override
    public $showScanner = false;

    public function mount($uuid)
    {
        $this->uuid = $uuid;
        
        $this->trip = TripInstance::where('uuid', $uuid)
            ->with([
                'tripTemplate',
                'bookings.passengers',
            ])
            ->firstOrFail();
    }

    public function checkInBooking($pnrOrId)
    {
        // First try PNR, then ID
        $booking = Booking::where('trip_instance_id', $this->trip->id)
            ->where(function($q) use ($pnrOrId) {
                $q->where('pnr', $pnrOrId)
                  ->orWhere('id', $pnrOrId);
            })
            ->first();

        if ($booking) {
            Passenger::where('booking_id', $booking->id)
                ->update(['is_checked_in' => true]);
                
            session()->flash('success', "تم تحضير جميع ركاب الحجز رقم: {$pnrOrId}");
        } else {
            session()->flash('error', "لم يتم العثور على الحجز: {$pnrOrId}");
        }
        
        // Refresh trip data to update counts
        $this->trip->refresh();
    }
    
    public function togglePassenger($passengerId)
    {
        $passenger = Passenger::find($passengerId);
        if ($passenger) {
            $passenger->is_checked_in = !$passenger->is_checked_in;
            $passenger->save();
        }
        $this->trip->refresh();
    }

    public function render()
    {
        $passengers = Passenger::whereHas('booking', function ($q) {
                $q->where('trip_instance_id', $this->trip->id);
            })
            ->when($this->searchTerm, function ($q) {
                $q->where(function ($query) {
                    $query->where('first_name', 'like', '%' . $this->searchTerm . '%')
                          ->orWhere('last_name', 'like', '%' . $this->searchTerm . '%')
                          ->orWhere('seat_number', 'like', '%' . $this->searchTerm . '%');
                });
            })
            ->with(['booking', 'tripPassengerCategory'])
            ->orderByRaw('ISNULL(seat_number), seat_number ASC')
            ->get();
            
        $totalPassengers = $passengers->count();
        $checkedInCount = $passengers->where('is_checked_in', true)->count();
        
        return view('livewire.tour-guide-manifest', [
            'passengers' => $passengers,
            'totalPassengers' => $totalPassengers,
            'checkedInCount' => $checkedInCount,
        ]);
    }
}
