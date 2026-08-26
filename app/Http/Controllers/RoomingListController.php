<?php

namespace App\Http\Controllers;

use App\Models\RoomAssignment;
use App\Models\TripStayLegHotelOption;
use Spatie\Browsershot\Browsershot;
use Spatie\LaravelPdf\Facades\Pdf;

/**
 * Hotel/Rooming redesign Ticket 3 — per-hotel printable rooming list, the voucher/confirmation
 * document sent to the hotel. Follows ManifestController's exact pattern (a plain GET route
 * returning a Pdf::view() response directly) — same library, same shape, nothing new introduced.
 */
class RoomingListController extends Controller
{
    public function generate(TripStayLegHotelOption $hotelOption)
    {
        $hotelOption->load(['hotel', 'tripStayLeg.tripInstance.tripTemplate', 'roomTypes.roomInstances']);

        $roomInstanceIds = $hotelOption->roomTypes->flatMap(fn ($rt) => $rt->roomInstances)->pluck('id');

        $assignments = RoomAssignment::query()
            ->whereIn('room_instance_id', $roomInstanceIds)
            ->with(['passenger.booking.customer', 'roomInstance.roomType'])
            ->get()
            ->groupBy('room_instance_id');

        // Every physical room instance is listed, occupied or not — a hotel voucher needs the
        // full room count, not just the ones currently filled.
        $rooms = $hotelOption->roomTypes->flatMap(function ($roomType) use ($assignments) {
            return $roomType->roomInstances->map(fn ($instance) => [
                'room_type' => $roomType->name,
                'room_number' => $instance->room_number,
                'capacity' => $roomType->capacity_per_room,
                'occupants' => ($assignments->get($instance->id) ?? collect())
                    ->map(fn (RoomAssignment $a) => [
                        'name' => $a->passenger->display_name,
                        'pnr' => $a->passenger->booking?->pnr ?? '—',
                        'phone' => $a->passenger->booking?->customer?->phone ?? '—',
                    ]),
            ]);
        });

        $totalOccupants = $rooms->sum(fn ($r) => count($r['occupants']));

        return Pdf::view('pdf.rooming-list', [
            'hotelOption' => $hotelOption,
            'rooms' => $rooms,
            'totalOccupants' => $totalOccupants,
        ])
            ->withBrowsershot(function (Browsershot $browsershot) {
                // Production PDF Generator Crash fix (see zatara_audit_report.md, CRITICAL #5) —
                // same env-configured node/npm binary override as BookingSuccess::downloadPdf(),
                // instead of relying on the server process's inherited $PATH to contain "node".
                if (config('services.browsershot.node_path')) {
                    $browsershot->setNodeBinary(config('services.browsershot.node_path'));
                }
                if (config('services.browsershot.npm_path')) {
                    $browsershot->setNpmBinary(config('services.browsershot.npm_path'));
                }
            })
            ->format('A4')
            ->name('rooming-list-' . $hotelOption->id . '.pdf');
    }
}
