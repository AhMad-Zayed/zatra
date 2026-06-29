<?php

use Illuminate\Support\Facades\Route;
use App\Http\Middleware\ResolveStorefrontTenant;
use App\Http\Controllers\StorefrontController;
use App\Http\Controllers\PortalController;
use App\Livewire\Storefront\Checkout;

Route::middleware([ResolveStorefrontTenant::class])->group(function () {
    // Portal auth routes (Dashboard/Logout)
    Route::middleware(['auth:customer'])->group(function () {
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

    // Legal Documents
    Route::get('/legal/{document}', [\App\Http\Controllers\Storefront\LegalDocumentController::class, 'show'])->name('storefront.legal');

    // Omni-Auth Routes
    Route::get('/login', \App\Livewire\Auth\CustomerLogin::class)->name('portal.login');
    Route::get('/complete-profile', \App\Livewire\Auth\CompleteProfile::class)->name('portal.complete_profile');
    
    // Socialite Routes
    Route::get('/auth/{provider}', [\App\Http\Controllers\Auth\SocialAuthController::class, 'redirect'])->name('social.redirect');
    Route::get('/auth/{provider}/callback', [\App\Http\Controllers\Auth\SocialAuthController::class, 'callback'])->name('social.callback');
    
    // 2. Trip Details Page
    Route::get('/trip/{tripInstance}', \App\Livewire\TripDetails::class)
        ->name('storefront.trip.details')
        ->scopeBindings();
        
    // 3. One-Page Checkout Wizard
    Route::get('/checkout/{tripInstance}', \App\Livewire\CheckoutWizard::class)
        ->name('storefront.checkout')
        ->scopeBindings();
        
    // 4. Booking Success (PRG)
    Route::get('/booking/success/{uuid}', \App\Livewire\BookingSuccess::class)
        ->name('booking.success');
        
    // 5. Secure B2C Customer Portal
    Route::middleware(['auth:customer'])->group(function () {
        Route::get('/my-bookings', \App\Livewire\Storefront\MyBookings::class)->name('storefront.my-bookings');
        Route::get('/my-tickets/{booking}', [\App\Http\Controllers\Storefront\TicketController::class, 'download'])
            ->name('storefront.ticket.download');
    });
});

// --- SECURE B2B FILAMENT ROUTES ---
Route::middleware(['web', 'auth'])->get('/admin/secure-media/{media}', function (\Spatie\MediaLibrary\MediaCollections\Models\Media $media) {
    if ($media->collection_name !== 'identity_documents') {
        abort(403, 'Unauthorized media access.');
    }
    
    $passenger = $media->model;
    
    if (!$passenger) {
        abort(404, 'Associated record not found.');
    }

    $activeTenantId = \Filament\Facades\Filament::getTenant()?->id ?? auth()->user()->tenant_id;

    if ($passenger->tenant_id !== $activeTenantId) {
        abort(403, 'Unauthorized access to cross-tenant data. This attempt has been logged.');
    }

    return response()->file($media->getPath());
})->name('secure.media.download');

// --- WAITING LIST ROUTES ---
Route::get('/queue/redeem/{waitingList}', [\App\Http\Controllers\WaitingListController::class, 'redeem'])
    ->name('waiting-list.redeem')
    ->middleware('signed');
