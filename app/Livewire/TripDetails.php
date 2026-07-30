<?php

namespace App\Livewire;

use Livewire\Component;
use App\Models\Tenant;
use App\Models\TripTemplate;
use Livewire\Attributes\Layout;

#[Layout('components.layouts.storefront')]
class TripDetails extends Component
{
    public Tenant $tenant;
    public TripTemplate $tripTemplate;
    public $selectedInstanceId = null;
    public $selectedPackageId = null;
    public $instances = [];
    public $hasVariablePricing = false;

    public function mount(Tenant $tenant, TripTemplate $tripTemplate)
    {
        $this->tenant = $tenant;
        $this->tripTemplate = $tripTemplate->load(['media']);
        
        $this->instances = $this->tripTemplate->tripInstances()
            ->bookable()
            ->orderBy('start_date', 'asc')
            ->get();
            
        if ($this->instances->isNotEmpty()) {
            $this->selectedInstanceId = $this->instances->first()->id;
            
            // Check if prices differ
            $prices = $this->instances->map(function($i) {
                return $i->price_override ? $i->price_override_amount : ($this->tripTemplate->base_price ?? 0);
            })->unique();
            
            $this->hasVariablePricing = $prices->count() > 1;
        }
    }

    public function updatedSelectedInstanceId()
    {
        $this->selectedPackageId = null; // Reset package on instance change
    }

    public function getAvailablePackagesProperty()
    {
        $selectedInstance = $this->instances->firstWhere('id', $this->selectedInstanceId);
        if (!$selectedInstance) {
            return collect();
        }
        return $selectedInstance->activePackageOptions;
    }

    public function getFinalPriceProperty(): int
    {
        $selectedInstance = $this->instances->firstWhere('id', $this->selectedInstanceId);
        if (!$selectedInstance) {
            return $this->tripTemplate->base_price ?? 0;
        }

        $base = $selectedInstance->price_override 
            ? $selectedInstance->price_override_amount 
            : ($this->tripTemplate->base_price ?? 0);
            
        $adj = \App\Models\PackageOption::find($this->selectedPackageId)?->price_adjustment ?? 0;
        return $base + $adj;
    }

    public function render()
    {
        return view('livewire.trip-details', [
            'tenant' => $this->tenant,
            'template' => $this->tripTemplate,
            'instances' => $this->instances,
            'selectedInstance' => $this->instances->firstWhere('id', $this->selectedInstanceId),
        ]);
    }
}
