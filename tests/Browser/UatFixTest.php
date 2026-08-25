<?php

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\DuskTestCase;
use App\Models\User;
use App\Models\Customer;
use App\Models\TripInstance;
use App\Models\PackageOption;
use App\Models\TripPassengerCategory;

class UatFixTest extends DuskTestCase
{
    public function testRedirectFix()
    {
        $this->browse(function (Browser $browser) {
            $user = User::where('email', 'admin@zatara.com')->first();
            $url = 'http://127.0.0.1:8001';
            $tenant = \App\Models\Tenant::first();
            
            $customer = Customer::firstOrCreate(['tenant_id' => $tenant->id, 'name' => 'Dusk Test Customer', 'phone' => '0599000000']);
            $trip = TripInstance::where('status', 'active')->first();
            
            $package = PackageOption::firstOrCreate([
                'tenant_id' => $tenant->id,
                'trip_instance_id' => $trip->id,
                'name' => 'Test Package',
                'hotel_name' => 'Test Hotel',
                'room_type' => 'double',
                'price_adjustment' => 0,
            ]);
            
            $category = TripPassengerCategory::first();

            $browser->visit($url . '/admin/login')
                    ->type('#data\.email', 'admin@zatara.com')
                    ->type('#data\.password', 'password')
                    ->click('button[type="submit"]')
                    ->waitForText('رحلات اليوم', 15);
                    
            $tenantUrl = $browser->driver->getCurrentURL();
            
            $browser->visit($tenantUrl . '/bookings/create')
                    ->waitForText('العميل', 10);
            
            $browser->pause(2000);
            
            $browser->script([
                "let comp = Livewire.first();",
                "comp.set('data.customer_id', {$customer->id});",
                "comp.set('data.trip_instance_id', {$trip->id});",
                "comp.set('data.package_option_id', {$package->id});",
                "comp.set('data.passengers', [{'trip_passenger_category_id': {$category->id}, 'first_name': 'Test', 'last_name': 'Test', 'document_type': 'passport', 'document_number': '123456789', 'date_of_birth': '1990-01-01', 'gender': 'male'}]);",
                "comp.set('data.initial_payment_amount', 0);",
            ]);
            
            $browser->pause(2000);
            
            $browser->script("document.querySelector('.fi-form-actions button[type=submit]').click();");
            
            $browser->pause(5000); // wait for save and redirect
            
            echo "\nURL_AFTER_SAVING: " . $browser->driver->getCurrentURL() . "\n";
            $html = $browser->driver->getPageSource();
            if (str_contains($html, 'Print') || str_contains($html, 'WhatsApp') || str_contains($html, 'طباعة')) {
                 echo "ACTION_BUTTONS_PRESENT: true\n";
            } else {
                 echo "ACTION_BUTTONS_PRESENT: false\n";
            }
        });
    }
}
