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
    public string $searchDestination = '';
    public string $searchDate = '';

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
            ->when($this->searchDestination, fn($q) => $q->where('title', 'like', '%'.$this->searchDestination.'%'))
            ->when($this->searchDate, fn($q) => $q->whereHas('tripInstances', fn($tq) => $tq->where('start_date', '>=', $this->searchDate)))
            ->with(['tripInstances' => function ($query) {
                $query->bookable()->orderBy('start_date', 'asc')->with('tripPassengerCategories');
            }, 'media'])
            ->paginate(9);

        return view('livewire.storefront-catalog', [
            'tripTemplates' => $tripTemplates,
            'tenant' => $this->tenant,
        ]);
    }
}
