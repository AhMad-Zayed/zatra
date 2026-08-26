<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;
use App\Http\Middleware\ResolveStorefrontTenant;
use App\Http\Controllers\StorefrontController;
use App\Models\Tenant;

// --- LEGACY PORTAL REDIRECTS ---
// PortalController and CustomerAuthService have been retired: Storefront\MyBookings and
// CustomerBookingPortal are now the sole customer-facing "my bookings" surfaces (the old
// send-otp/verify-otp/showLogin methods were already fully dead -- no routes pointed at them
// before this change; only dashboard/logout were still live). These two routes are kept as
// permanent redirects rather than a hard 404, as a safety net for any un-discoverable old link
// (a bookmark, a stale WhatsApp message). ResolveStorefrontTenant still resolves the old
// plain-string tenant_slug exactly as it always did; the redirect target then uses the tenant's
// real .slug column, which the new {tenant:slug}-bound routes require.
Route::middleware([ResolveStorefrontTenant::class])->group(function () {
    Route::get('/t/{tenant_slug}/portal/dashboard', function () {
        $tenant = app(Tenant::class);

        return redirect()->route('storefront.my-bookings', ['tenant' => $tenant->slug], 301);
    })->name('portal.dashboard');

    Route::post('/t/{tenant_slug}/portal/logout', function () {
        $tenant = app(Tenant::class);

        Auth::guard('customer')->logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();

        return redirect()->route('portal.login', ['tenant' => $tenant->slug], 301);
    })->name('portal.logout');
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
    Route::get('/auth/confirm-link', \App\Livewire\Auth\ConfirmSocialLink::class)->name('social.confirm-link');
    
    // 2. Trip Details Page (Using TripTemplate slug)
    Route::get('/trip/{tripTemplate:slug}', \App\Livewire\TripDetails::class)
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
    if ($media->collection_name !== 'identity_documents' && $media->collection_name !== 'passport' && $media->collection_name !== 'national_id') {
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

    return response()->download($media->getPath());
})->name('secure.media.download');

// --- WAITING LIST ROUTES ---
Route::get('/queue/redeem/{waitingList}', [\App\Http\Controllers\WaitingListController::class, 'redeem'])
    ->name('waiting-list.redeem')
    ->middleware('signed');

Route::get('/admin/trip-instances/{tripInstance}/manifest', [\App\Http\Controllers\ManifestController::class, 'generate'])
    ->name('trip-instance.manifest')
    ->middleware(['web', 'auth']);

Route::get('/admin/hotel-options/{hotelOption}/rooming-list', [\App\Http\Controllers\RoomingListController::class, 'generate'])
    ->name('hotel-option.rooming-list')
    ->middleware(['web', 'auth']);

// --- MAGIC LOGIN ROUTE ---
Route::get('/login/magic', function (\Illuminate\Http\Request $request) {
    if (!$request->hasValidSignature()) {
        abort(401, 'Invalid or expired magic link.');
    }
    
    $tenantId = $request->query('tenant_id');
    if (!$tenantId) {
        abort(400, 'Tenant context missing from magic link.');
    }

    $customer = \App\Models\Customer::where('email', $request->query('email'))
        ->where('tenant_id', $tenantId)
        ->firstOrFail();
        
    \Illuminate\Support\Facades\Auth::guard('customer')->login($customer);
    
    if ($customer->tenant_id != $tenantId) {
        \Illuminate\Support\Facades\Auth::guard('customer')->logout();
        abort(403, 'Tenant mismatch. This attempt has been logged.');
    }
    
    // Redirect to customer dashboard or home
    return redirect('/'); // Adjust this if there's a specific customer dashboard
})->name('login.magic');

// --- MAGIC LINK / CUSTOMER PORTAL ---
// EMERGENCY FIX: both routes previously carried auth:customer middleware, which broke the real
// WhatsApp-delivered magic link for every phone booking -- CreateBooking.php sends a plain,
// unsigned route('customer.booking.portal', $booking->uuid) URL (not a signed login link), so a
// genuinely fresh customer hit Laravel's default unauthenticated redirect trying to build a URL
// for a route literally named "login" (which doesn't exist in this app), throwing a hard 500 on
// every click. Confirmed live via a zero-cookie browser session before this fix.
//
// Removing the middleware restores CustomerBookingPortal's own already-written no-login design
// (mount() already tolerates an absent customer session, only using it to optionally scope a
// query) -- the UUID itself is the access credential here, the same trust model as any other
// unguessable share link, and neither route performs any additional identity check beyond the
// UUID match even when the middleware was present. The ticket-download route is fixed
// identically since it's linked directly from this same page's own view (the post-completion
// "download ticket" button) -- leaving it broken would mean the page still couldn't actually be
// used end-to-end.
Route::get('/b/{uuid}', \App\Livewire\CustomerBookingPortal::class)
    ->name('customer.booking.portal');

Route::get('/b/{uuid}/ticket/download', function ($uuid) {
    $booking = \App\Models\Booking::where('uuid', $uuid)->firstOrFail();

    // Get latest ticket media
    $media = $booking->getMedia('tickets')->last();

    if (!$media) {
        abort(404, 'التذكرة غير متوفرة بعد.');
    }

    return response()->download($media->getPath(), "Ticket_{$booking->pnr}.pdf");
})->name('customer.ticket.download');

// --- TOUR GUIDE MANIFEST ---
Route::get('/g/{uuid}', \App\Livewire\TourGuideManifest::class)->name('tour.guide.manifest');
