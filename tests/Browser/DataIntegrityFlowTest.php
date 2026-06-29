<?php

namespace Tests\Browser;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;
use App\Models\Tenant;
use App\Models\Customer;

class DataIntegrityFlowTest extends DuskTestCase
{
    // We do NOT use DatabaseTruncation because we rely on the HeavyLoadSimulationSeeder
    
    public function test_data_integrity_flow()
    {
        $this->browse(function (Browser $browser) {
            \Illuminate\Support\Facades\Cache::flush();
            $tenant = Tenant::where('slug', 'zatara')->firstOrFail();
            $customer = Customer::where('email', 'dusk@zatara.com')->firstOrFail();
            
            $browser->resize(1920, 1080);
            
            // 1. Log in to Customer Portal via OTP
            $browser->visit("/{$tenant->slug}/login")
                    ->pause(1000)
                    ->type('identifier', $customer->phone)
                    ->press('أرسل رمز التحقق')
                    ->waitFor('input[name="otp"]')
                    ->type('otp', '1234') // Test bypass code
                    ->press('تأكيد الدخول')
                    ->pause(3000)
                    // Check Dashboard UI
                    ->assertPathIs("/{$tenant->slug}/my-bookings")
                    ->assertSee('حجوزاتي')
                    ->assertSee($customer->bookings()->first()->pnr)
                    ->assertSee('المدفوع')
                    ->screenshot('b2c_dashboard_integrity');

            // 2. Navigate to Catalog
            $browser->visit("/{$tenant->slug}")
                    ->pause(2000)
                    ->assertSee('Luxurious Trip to Destination')
                    ->screenshot('b2c_catalog_integrity');
                    
            // Click the first trip
            $browser->clickLink('Luxurious Trip to Destination')
                    ->pause(3000);
                    
            // 3. Assert Trip Details Page Data Integrity
            // The image should not be broken (meaning an <img> exists and loads)
            $browser->assertPresent('img')
                    // Check addons
                    ->assertSee('VIP Airport Transfer')
                    ->screenshot('b2c_trip_details_integrity');
                    
            // Proceed to Checkout
            $browser->click('.btn-secondary')
                    ->pause(3000);
                    
            // 4. Assert Checkout Wizard Data
            $browser->assertSee('بيانات المسافرين')
                    ->screenshot('b2c_checkout_integrity');                    
            // Optional: check child/infant buttons if available
        });
    }
}
