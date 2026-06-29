<?php

namespace App\Livewire;

use App\Models\Booking;
use App\Models\Tenant;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Database\Eloquent\Collection;

#[Layout('components.layouts.storefront')]
class CustomerPortal extends Component
{
    public Tenant $tenant;
    
    // We don't want to load bookings as a public property if it's large, 
    // but for the portal, we can fetch them via a computed property or mount.
    public function mount(Tenant $tenant)
    {
        $this->tenant = $tenant;
        
        // Strict Authorization check (should be handled by middleware, but good as defense-in-depth)
        if (!Auth::guard('customer')->check()) {
            return redirect()->route('storefront.catalog', ['tenant_slug' => $tenant->slug]);
        }
    }

    /**
     * Fetch bookings with strict tenant and customer isolation.
     */
    #[\Livewire\Attributes\Computed]
    public function bookings(): Collection
    {
        $customer = Auth::guard('customer')->user();

        // STRICT SCOPING: Must belong to current auth customer AND current tenant.
        return Booking::where('customer_id', $customer->id)
            ->where('tenant_id', $this->tenant->id)
            ->with(['tripInstance.template']) // Eager load the trip details
            ->latest()
            ->get();
    }

    public function render()
    {
        return view('livewire.customer-portal', [
            'bookings' => $this->bookings
        ]);
    }
}
