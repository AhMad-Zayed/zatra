<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Spatie\Browsershot\Browsershot;
use Spatie\LaravelPdf\Facades\Pdf; // Or Barryvdh\DomPDF\Facade\Pdf depending on installation

class TicketDownloadController extends Controller
{
    /**
     * Download the E-Ticket for a specific booking.
     * Enforces strict Tenant and Customer isolation.
     */
    public function __invoke(Request $request, string $tenant_slug, Booking $booking)
    {
        $tenant = Tenant::where('slug', $tenant_slug)->firstOrFail();
        $customer = Auth::guard('customer')->user();

        // 1. STRICT SECURITY VETO: Verify ownership and tenant
        if ($booking->customer_id !== $customer->id || $booking->tenant_id !== $tenant->id) {
            abort(403, 'Unauthorized. This booking does not belong to your account.');
        }

        // 2. LOGICAL VERIFICATION: Ensure the booking is paid and confirmed
        if ($booking->payment_status !== \App\Enums\PaymentStatus::Paid || $booking->booking_status !== \App\Enums\BookingStatus::Confirmed) {
            abort(403, 'Tickets are only available for fully paid and confirmed bookings.');
        }

        // 3. GENERATE PDF
        // Using spatie/laravel-pdf because it natively supports Tailwind CSS rendering via Browsershot,
        // which perfectly aligns with our luxury Tailwind UI requirements.
        $pdf = Pdf::view('pdf.e-ticket', [
            'booking' => $booking->load(['tripInstance.template', 'passengers.tripPricingTier']),
            'tenant' => $tenant,
            'customer' => $customer,
        ])
        ->withBrowsershot(function (Browsershot $browsershot) {
            // Production PDF Generator Crash fix (see zatara_audit_report.md, CRITICAL #5) — same
            // env-configured node/npm binary override as BookingSuccess::downloadPdf(), instead
            // of relying on the server process's inherited $PATH to contain "node".
            if (config('services.browsershot.node_path')) {
                $browsershot->setNodeBinary(config('services.browsershot.node_path'));
            }
            if (config('services.browsershot.npm_path')) {
                $browsershot->setNpmBinary(config('services.browsershot.npm_path'));
            }
        })
        ->format('a4')
        ->name("Zatara-Ticket-{$booking->id}.pdf");

        return $pdf->download();
    }
}
