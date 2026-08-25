<?php

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class UatEndToEndTest extends DuskTestCase
{
    public function testEndToEnd()
    {
        $this->browse(function (Browser $browser) {
            $url = 'http://127.0.0.1:8001';

            $browser->visit($url . '/admin/login')
                    ->type('#data\.email', 'admin@zatara.com')
                    ->type('#data\.password', 'password')
                    ->click('button[type="submit"]')
                    ->waitForText('رحلات اليوم', 15);
                    
            $tenantUrl = $browser->driver->getCurrentURL();
            
            // Go to Trips
            $browser->visit($tenantUrl . '/trip-instances')
                    ->waitForText('الرحلات المجدولة', 10);
            
            $html = $browser->driver->getPageSource();
            file_put_contents('trips_table.html', $html);
            
            // Go to Bookings Table
            $browser->visit($tenantUrl . '/bookings')
                    ->waitForText('الحجوزات', 10);
            
            $html = $browser->driver->getPageSource();
            file_put_contents('bookings_table.html', $html);
            
            // Dashboard unpaid link
            $browser->visit($tenantUrl)
                    ->waitForText('رحلات اليوم', 10);
            
            $html = $browser->driver->getPageSource();
            file_put_contents('dashboard.html', $html);
        });
    }
}
