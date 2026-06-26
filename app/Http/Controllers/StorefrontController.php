<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\TripInstance;
use Illuminate\Http\Request;

class StorefrontController extends Controller
{
    public function index(Request $request)
    {
        $tenant = app(Tenant::class);

        // Fetch active future trip instances scoped to the tenant
        $tripInstances = TripInstance::where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->where('start_date', '>=', now()->startOfDay())
            ->with('tripTemplate')
            ->get();

        return view('storefront.home', compact('tenant', 'tripInstances'));
    }
}
