<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\TripInstance;
use App\Models\Passenger;
use Barryvdh\DomPDF\Facade\Pdf;

class ManifestController extends Controller
{
    public function generate(TripInstance $tripInstance)
    {
        // Load passengers with their booking, booking category, and pickup point
        // Group by pickup point
        $tripInstance->load(['tripTemplate']);

        // Since we created BookingPickup, we can query passengers where their booking is related to this trip instance.
        $passengers = Passenger::whereHas('booking', function ($query) use ($tripInstance) {
            $query->where('trip_instance_id', $tripInstance->id)
                  ->where('booking_status', '!=', 'cancelled');
        })
        ->with(['booking', 'tripPassengerCategory', 'bookingPickups.pickupPoint.pickupRoute'])
        ->get();

        // Map and sort passengers by pickup point
        $passengersList = $passengers->map(function ($passenger) {
            $pickup = $passenger->bookingPickups->first();
            $pickupPoint = $pickup ? $pickup->pickupPoint : null;
            return [
                'name' => $passenger->first_name . ' ' . $passenger->last_name,
                'phone' => $passenger->booking->phone,
                'pnr' => $passenger->booking->pnr,
                'category' => $passenger->tripPassengerCategory->name,
                'pickup_name' => $pickupPoint ? $pickupPoint->name : 'تجمع ذاتي',
                'pickup_time' => $pickupPoint ? $pickupPoint->pickup_time : 'N/A',
                'pickup_order' => $pickupPoint ? $pickupPoint->order : 9999,
            ];
        })->sortBy(['pickup_order', 'pickup_time'])->values();

        // Group by pickup point
        $groupedPassengers = $passengersList->groupBy('pickup_name');

        $pdf = Pdf::loadView('pdf.manifest', [
            'tripInstance' => $tripInstance,
            'groupedPassengers' => $groupedPassengers,
            'totalPassengers' => $passengers->count()
        ]);

        return $pdf->stream('manifest-' . $tripInstance->id . '.pdf');
    }
}
