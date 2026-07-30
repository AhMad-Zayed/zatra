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
        $tripTemplates = \App\Models\TripTemplate::where('tenant_id', $this->tenant->id)
            ->where('is_active', true)
            ->whereHas('tripInstances', function ($query) {
                $query->bookable();
            })
            ->with(['tripInstances' => function ($query) {
                $query->bookable()->orderBy('start_date', 'asc');
            }, 'media'])
            ->paginate(9);

        return view('livewire.storefront-catalog', [
            'tripTemplates' => $tripTemplates,
            'tenant' => $this->tenant,
        ]);
    }
}
