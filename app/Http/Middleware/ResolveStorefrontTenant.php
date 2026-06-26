<?php

namespace App\Http\Middleware;

use Closure;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ResolveStorefrontTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenantSlug = $request->route('tenant_slug');

        if (!$tenantSlug) {
            $firstTenant = Tenant::first();
            if ($firstTenant) {
                $slug = Str::slug($firstTenant->name);
                return redirect()->route('storefront.home', ['tenant_slug' => $slug]);
            }
            abort(404, 'No tenants found.');
        }

        // Find tenant by slugified name or domain
        $tenant = Tenant::all()->first(function ($t) use ($tenantSlug) {
            return Str::slug($t->name) === $tenantSlug || $t->domain === $tenantSlug;
        });

        if (!$tenant) {
            abort(404, 'Tenant not found.');
        }

        // Bind resolved tenant to container
        app()->instance(Tenant::class, $tenant);

        return $next($request);
    }
}
