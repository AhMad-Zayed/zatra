<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'tenant.customer' => \App\Http\Middleware\TenantCustomerMiddleware::class,
        ]);

        // Phase 6: Exempt Payment Webhooks from CSRF
        $middleware->validateCsrfTokens(except: [
            'api/webhooks/*',
        ]);

        // This app has no route literally named "login" -- Laravel's own default guest-redirect
        // closure (route('login')) throws RouteNotFoundException for any auth/auth:customer
        // middleware failure. Filament's panel guards are unaffected: Filament\Http\Middleware\
        // Authenticate overrides redirectTo() directly rather than using this callback. For a
        // tenant-scoped customer route (anything under the {tenant:slug} prefix, e.g.
        // storefront.my-bookings), redirect straight to that tenant's real login page instead of
        // crashing. For anything else (e.g. the plain 'auth'-protected staff download routes in
        // routes/web.php), fall back to the home page rather than a 500 -- those are only ever
        // reached by an already-logged-in staff member via a Filament-rendered link, so this path
        // is a safety net, not a real user-facing destination.
        $middleware->redirectGuestsTo(function (Request $request) {
            $tenantParam = $request->route('tenant');

            // SubstituteBindings (route-model-binding) runs AFTER auth in Laravel's default
            // middleware priority, so $tenantParam is still the raw {tenant:slug} string here,
            // not a hydrated model -- resolve it the same way the binding itself would.
            $tenant = $tenantParam instanceof \App\Models\Tenant
                ? $tenantParam
                : \App\Models\Tenant::where('slug', $tenantParam)->first();

            if ($tenant) {
                return route('portal.login', ['tenant' => $tenant->slug]);
            }

            return '/';
        });
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
