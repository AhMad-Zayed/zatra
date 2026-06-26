<?php

use Illuminate\Support\Facades\Route;
use App\Http\Middleware\ResolveStorefrontTenant;
use App\Http\Controllers\StorefrontController;
use App\Http\Controllers\PortalController;
use App\Livewire\Storefront\Checkout;

Route::middleware([ResolveStorefrontTenant::class])->group(function () {
    Route::get('/t/{tenant_slug}', [StorefrontController::class, 'index'])->name('storefront.home');
    Route::get('/t/{tenant_slug}/checkout/{instance}', Checkout::class)->name('storefront.checkout');
    
    // Portal guest routes
    Route::middleware(['guest'])->group(function () {
        Route::get('/t/{tenant_slug}/portal', [PortalController::class, 'showLogin'])->name('portal.login');
        Route::post('/t/{tenant_slug}/portal/send-otp', [PortalController::class, 'sendOtp'])->name('portal.send_otp');
        Route::post('/t/{tenant_slug}/portal/verify-otp', [PortalController::class, 'verifyOtp'])->name('portal.verify_otp');
    });

    // Portal auth routes
    Route::middleware(['auth'])->group(function () {
        Route::get('/t/{tenant_slug}/portal/dashboard', [PortalController::class, 'dashboard'])->name('portal.dashboard');
        Route::post('/t/{tenant_slug}/portal/logout', [PortalController::class, 'logout'])->name('portal.logout');
    });
});

Route::get('/', function () {
    return view('welcome');
});

// --- PUBLIC B2C STOREFRONT ROUTES ---
Route::prefix('{tenant:slug}')->middleware(['tenant.customer'])->group(function () {
    
    // 1. Catalog Page (Landing)
    Route::get('/', \App\Livewire\StorefrontCatalog::class)->name('storefront.catalog');
    
    // 2. Trip Details Page
    Route::get('/trip/{tripInstance}', \App\Livewire\TripDetails::class)
        ->name('storefront.trip.details')
        ->scopeBindings();
        
    // 3. One-Page Checkout Wizard
    Route::get('/checkout/{tripInstance}', \App\Livewire\CheckoutWizard::class)
        ->name('storefront.checkout')
        ->scopeBindings();
        
});
