<?php

namespace App\Livewire;

use Livewire\Component;
use App\Models\Tenant;
use App\Models\TripInstance;
use Livewire\Attributes\Layout;

use Livewire\WithPagination;

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
        $tripInstances = TripInstance::where('tenant_id', $this->tenant->id)
            ->where('status', 'active')
            ->with(['tripTemplate', 'tripPassengerCategories'])
            ->orderBy('start_date', 'asc')
            ->paginate(9);

        return view('livewire.storefront-catalog', [
            'tripInstances' => $tripInstances,
            'tenant' => $this->tenant,
        ]);
    }
}
