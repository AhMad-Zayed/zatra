<?php

namespace App\Livewire;

use Livewire\Component;
use Livewire\WithPagination;
use App\Models\Tenant;
use App\Models\TripInstance;
use Illuminate\Support\Facades\View;
use Livewire\Attributes\Layout;

#[Layout('components.layouts.storefront')]
class StorefrontCatalog extends Component
{
    use WithPagination;

    public Tenant $tenant;

    public function mount(Tenant $tenant)
    {
        $this->tenant = $tenant;
    }

    public function render()
    {
        // Enforce strict eager loading and pagination as requested
        $trips = TripInstance::where('tenant_id', $this->tenant->id)
            ->where('status', 'upcoming') // Assuming status enum or active flag, we'll just pull upcoming ones, or just available ones
            ->with(['tripTemplate', 'tripPricingTiers'])
            ->orderBy('start_date', 'asc')
            ->paginate(12);

        return view('livewire.storefront-catalog', [
            'trips' => $trips,
        ]);
    }
}
