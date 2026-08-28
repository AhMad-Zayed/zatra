<?php

namespace App\Livewire;

use Livewire\Component;
use App\Models\Tenant;
use App\Models\TripInstance;
use App\Enums\TripTypeEnum;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\URL;
use Livewire\Attributes\Layout;

use Livewire\WithPagination;

#[Layout('components.layouts.storefront')]
class StorefrontCatalog extends Component
{
    use WithPagination;

    public Tenant $tenant;
    public string $searchDestination = '';
    public string $searchDate = '';

    /**
     * Storefront redesign Phase F, item 1: filter sidebar. TripTypeEnum (domestic/international)
     * is the only real classification field already on TripTemplate meant for exactly this
     * ("Pure classification ... for filtering/reporting purposes only", per its own docblock) --
     * reused as-is instead of inventing a new taxonomy.
     */
    public array $categories = [];
    public ?int $priceMin = null;
    public ?int $priceMax = null;

    public function mount(Tenant $tenant)
    {
        $this->tenant = $tenant;
    }

    public function updating($name)
    {
        if (in_array($name, ['searchDestination', 'searchDate', 'categories', 'priceMin', 'priceMax'])) {
            $this->resetPage();
        }
    }

    public function resetFilters()
    {
        $this->priceMin = null;
        $this->priceMax = null;
        $this->categories = [];
        $this->resetPage();
    }

    public function render()
    {
        $baseQuery = \App\Models\TripTemplate::where('tenant_id', $this->tenant->id)
            ->where('is_active', true)
            ->whereHas('tripInstances', function ($query) {
                $query->bookable();
            })
            ->when($this->searchDestination, fn($q) => $q->where('title', 'like', '%'.$this->searchDestination.'%'))
            ->when($this->searchDate, fn($q) => $q->whereHas('tripInstances', fn($tq) => $tq->where('start_date', '>=', $this->searchDate)))
            ->when(!empty($this->categories), fn($q) => $q->whereIn('trip_type', $this->categories))
            ->with(['tripInstances' => function ($query) {
                $query->bookable()->orderBy('start_date', 'asc')->with('tripPassengerCategories');
            }, 'media']);

        // starting_price is a computed accessor (base_price, falling back to the lowest bookable
        // category price) -- there's no single DB column to range-filter against without
        // duplicating that logic in raw SQL, so price filtering happens in PHP against the
        // already-loaded collection, then gets paginated manually. Fine at this catalog's scale
        // (a handful of trip templates per tenant, not a mass-inventory listing).
        $allMatching = $baseQuery->get();

        $priceCeiling = (int) ceil($allMatching->max('starting_price') ?? 0);

        if ($this->priceMin !== null) {
            $allMatching = $allMatching->filter(fn($t) => $t->starting_price >= $this->priceMin);
        }
        if ($this->priceMax !== null) {
            $allMatching = $allMatching->filter(fn($t) => $t->starting_price <= $this->priceMax);
        }
        $allMatching = $allMatching->values();

        $perPage = 9;
        $currentPage = $this->getPage();
        $tripTemplates = new LengthAwarePaginator(
            $allMatching->forPage($currentPage, $perPage),
            $allMatching->count(),
            $perPage,
            $currentPage,
            ['path' => URL::current(), 'pageName' => 'page']
        );

        return view('livewire.storefront-catalog', [
            'tripTemplates' => $tripTemplates,
            'tenant' => $this->tenant,
            'priceCeiling' => $priceCeiling,
            'categoryOptions' => TripTypeEnum::cases(),
            // Explicitly passed (rather than relying on Livewire's implicit public-property ->
            // view-variable extraction) because StorefrontCatalogPerformanceTest renders this
            // component via direct instantiation + render() -- deliberately bypassing Livewire's
            // full request lifecycle to get an honest, un-doubled query count -- which means that
            // implicit extraction never happens. Real Livewire requests already receive these
            // through both paths harmlessly.
            'priceMin' => $this->priceMin,
            'priceMax' => $this->priceMax,
            'categories' => $this->categories,
            'searchDestination' => $this->searchDestination,
            'searchDate' => $this->searchDate,
        ]);
    }
}
