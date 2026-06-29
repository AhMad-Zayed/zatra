<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class TicketController extends Controller
{
    public function download(Request $request, \App\Models\Tenant $tenant, \App\Models\Booking $booking)
    {
        if ($booking->customer_id !== auth('customer')->id()) {
            abort(403, 'Unauthorized access to this ticket.');
        }

        $media = $booking->getFirstMedia('tickets');
        if (!$media) {
            abort(404, 'Ticket not generated yet.');
        }

        return response()->download($media->getPath(), 'BoardingPass_'.$booking->pnr_code.'.pdf');
    }
}
