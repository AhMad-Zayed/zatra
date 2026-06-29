<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Schedule::command('app:release-expired-holds')->everyFiveMinutes()->withoutOverlapping();

// Automation Engine
Schedule::job(new \App\Jobs\AbandonedCartRecovery)->everyThirtyMinutes();
Schedule::job(new \App\Jobs\YieldPricingJob)->hourly();
Schedule::job(new \App\Jobs\PreDepartureReminder)->dailyAt('20:00');
Schedule::job(new \App\Jobs\AutoManifestDistribution)->dailyAt('20:00');
Schedule::job(new \App\Jobs\PostTripReviewRequest)->dailyAt('10:00');
