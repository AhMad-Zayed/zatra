<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Models\Payment;
use App\Models\Booking;
use App\Observers\PaymentObserver;
use App\Observers\BookingObserver;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Payment::observe(PaymentObserver::class);
        Booking::observe(BookingObserver::class);

        \Illuminate\Support\Facades\Gate::before(function ($user, $ability) {
            return $user->is_super_admin ? true : null;
        });
    }
}
