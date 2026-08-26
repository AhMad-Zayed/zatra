<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\TripInstance;
use App\Models\Passenger;
use Spatie\Browsershot\Browsershot;
use Spatie\LaravelPdf\Facades\Pdf;

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
        ->with(['booking.bookingPickups.pickupPoint.pickupRoute', 'tripPassengerCategory', 'booking.customer'])
        ->get();

        // Map and sort passengers by pickup point
        $passengersList = $passengers->map(function ($passenger) {
            $pickup = $passenger->booking?->bookingPickups->first();
            $pickupPoint = $pickup ? $pickup->pickupPoint : null;
            return [
                'name' => $passenger->first_name . ' ' . $passenger->last_name,
                'phone' => $passenger->booking?->customer?->phone ?? $passenger->booking?->user?->phone ?? 'N/A',
                'pnr' => $passenger->booking?->pnr ?? 'N/A',
                'category' => $passenger->tripPassengerCategory?->name ?? 'N/A',
                'pickup_name' => $pickupPoint ? $pickupPoint->name : 'تجمع ذاتي',
                'pickup_time' => $pickupPoint ? $pickupPoint->pickup_time : 'N/A',
                'pickup_order' => $pickupPoint ? $pickupPoint->order : 9999,
            ];
        })->sortBy(['pickup_order', 'pickup_time'])->values();

        // Group by pickup point
        $groupedPassengers = $passengersList->groupBy('pickup_name');

        return Pdf::view('pdf.manifest', [
            'tripInstance' => $tripInstance,
            'groupedPassengers' => $groupedPassengers,
            'totalPassengers' => $passengers->count()
        ])
        ->withBrowsershot(function (Browsershot $browsershot) {
            // Production PDF Generator Crash fix (see zatara_audit_report.md, CRITICAL #5):
            // Browsershot's default shell command resolves "node"/"npm" from the server
            // process's inherited $PATH, which is not guaranteed to include them. Overriding
            // via env-configured binaries — the same fix already applied in
            // BookingSuccess::downloadPdf() — removes that dependency.
            if (config('services.browsershot.node_path')) {
                $browsershot->setNodeBinary(config('services.browsershot.node_path'));
            }
            if (config('services.browsershot.npm_path')) {
                $browsershot->setNpmBinary(config('services.browsershot.npm_path'));
            }
        })
        ->format('A4')
        ->name('manifest-' . $tripInstance->id . '.pdf');
    }
}
