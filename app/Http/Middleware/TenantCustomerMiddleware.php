<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TenantCustomerMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $routeTenant = $request->route('tenant');
        $tenantId = $routeTenant instanceof \App\Models\Tenant ? $routeTenant->id : null;

        // Share the tenant globally to all views if it exists
        if ($routeTenant instanceof \App\Models\Tenant) {
            \Illuminate\Support\Facades\View::share('currentTenant', $routeTenant);
        }

        if (\Illuminate\Support\Facades\Auth::guard('customer')->check()) {
            $customer = \Illuminate\Support\Facades\Auth::guard('customer')->user();

            if (!$tenantId || $customer->tenant_id !== $tenantId) {
                \Illuminate\Support\Facades\Auth::guard('customer')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                
                if ($routeTenant instanceof \App\Models\Tenant) {
                    return redirect()->route('storefront.catalog', ['tenant' => $routeTenant->slug]);
                }
                
                return redirect('/');
            }
        }

        return $next($request);
    }
}
