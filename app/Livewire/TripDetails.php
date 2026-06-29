<?php

namespace App\Livewire;

use Livewire\Component;
use App\Models\Tenant;
use App\Models\TripInstance;
use Livewire\Attributes\Layout;

#[Layout('components.layouts.storefront')]
class TripDetails extends Component
{
    public Tenant $tenant;
    public TripInstance $tripInstance;

    public function mount(Tenant $tenant, TripInstance $tripInstance)
    {
        $this->tenant = $tenant;
        // Load relationships
        $this->tripInstance = $tripInstance->load(['tripTemplate', 'tripPassengerCategories']);
    }

    public function render()
    {
        return view('livewire.trip-details', [
            'tenant' => $this->tenant,
            'instance' => $this->tripInstance,
        ]);
    }
}
