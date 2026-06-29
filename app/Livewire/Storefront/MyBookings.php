<?php

namespace App\Livewire\Storefront;

use Livewire\Component;
use App\Models\Tenant;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use App\Models\Booking;

#[Layout('components.layouts.storefront')]
class MyBookings extends Component
{
    public Tenant $tenant;

    public function mount(Tenant $tenant)
    {
        $this->tenant = $tenant;
    }

    public function requestCancellation($bookingId)
    {
        $booking = Booking::findOrFail($bookingId);

        // Security check
        if ($booking->customer_id !== Auth::guard('customer')->id()) {
            abort(403);
        }

        // Only allow if not already requested and trip is in future
        if ($booking->cancellation_requested_at !== null) {
            return;
        }
        
        if ($booking->tripInstance->start_date <= now()) {
            // Can't cancel past trips
            return;
        }

        $booking->update([
            'cancellation_requested_at' => now(),
        ]);

        // Optional: Dispatch a notification to Tenant Admin
        \Filament\Notifications\Notification::make()
            ->title('طلب إلغاء حجز جديد')
            ->body('العميل ' . Auth::guard('customer')->user()->name . ' طلب إلغاء الحجز PNR: ' . $booking->pnr_code)
            ->warning()
            ->sendToDatabase(\App\Models\User::where('tenant_id', $this->tenant->id)->get());
    }

    public function render()
    {
        $bookings = Booking::with(['tripInstance.tripTemplate'])
            ->where('tenant_id', $this->tenant->id)
            ->where('customer_id', Auth::guard('customer')->id())
            ->orderByDesc('created_at')
            ->get();

        return view('livewire.storefront.my-bookings', [
            'bookings' => $bookings
        ]);
    }
}
