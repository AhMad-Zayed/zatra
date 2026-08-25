<?php

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class UatTest extends DuskTestCase
{
    public function testDashboardAndRedirect()
    {
        $this->browse(function (Browser $browser) {
            $url = env('APP_URL');
            echo "Visiting: " . $url . "\n";
            $browser->visit($url . '/admin/login');
            $browser->screenshot('login_page');
        });
    }
}
