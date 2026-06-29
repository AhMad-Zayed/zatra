<?php

namespace Tests\Browser;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;
use App\Models\Tenant;
use App\Models\TripTemplate;
use App\Models\TripInstance;
use App\Models\TripPassengerCategory;

class CheckoutFlowTest extends DuskTestCase
{
    // Removing DatabaseMigrations so teardown doesn't crash on bad migration rollbacks.

    public function test_full_checkout_flow()
    {
        // 1. Setup Test Data (using firstOrCreate to prevent duplicates since DB is persistent)
        $tenant = \App\Models\Tenant::firstOrCreate(
            ['slug' => 'auto-agency'],
            ['name' => 'Automated Agency', 'domain' => 'auto.zatara.com']
        );

        $tripTemplate = \App\Models\TripTemplate::firstOrCreate(
            ['tenant_id' => $tenant->id, 'title' => 'Automated UI Test Trip'],
            ['description' => 'A trip created specifically for E2E browser testing.', 'days' => 5, 'nights' => 4, 'is_active' => true, 'base_price' => 500]
        );

        $tripInstance = \App\Models\TripInstance::firstOrCreate(
            ['trip_template_id' => $tripTemplate->id],
            ['tenant_id' => $tenant->id, 'start_date' => '2026-07-03', 'end_date' => '2026-07-08', 'available_seats' => 20, 'status' => 'active']
        );

        $pricingTier = \App\Models\TripPassengerCategory::firstOrCreate(
            ['trip_instance_id' => $tripInstance->id, 'tenant_id' => $tenant->id],
            ['name' => 'Adult Standard', 'price' => 500, 'min_age' => 12]
        );

        $this->browse(function (Browser $browser) use ($tenant, $tripInstance, $pricingTier) {
            try {
                $browser->visitRoute('storefront.checkout', ['tenant' => $tenant->slug, 'tripInstance' => $tripInstance->id])
                        // STEP 1: Phone
                        ->waitForText('رقم الجوال') // Wait for Livewire to render the component
                        ->type('#phone', '966500000000')
                        ->press('متابعة')

                        
                        // STEP 2: OTP (Using bypass '000000' based on the CheckoutWizard controller)
                        ->waitForText('رمز التحقق')
                        ->type('#otp', '000000')
                        ->press('تحقق')

                        
                        // STEP 3: Passengers
                        ->waitForText('الاسم الأول')
                        ->type('input[wire\:model="form.passengers.0.first_name"]', 'Automated')
                        ->type('input[wire\:model="form.passengers.0.last_name"]', 'Tester')
                        ->select('select[wire\:model="form.passengers.0.document_type"]', 'passport')
                        ->type('input[wire\:model="form.passengers.0.document_number"]', 'A1234567')
                        ->type('input[wire\:model="form.passengers.0.date_of_birth"]', '1990-01-01')
                        ->select('select[wire\:model="form.passengers.0.trip_passenger_category_id"]', (string)$pricingTier->id)
                        ->press('متابعة للإضافات')
                        
                        // STEP 4: Addons
                        ->waitForText('الإضافات الاختيارية')
                        ->press('متابعة للدفع')
                        
                        // STEP 5: Payment
                        ->waitForText('طريقة الدفع')
                        ->script("document.querySelector('input[value=\"cash\"]').parentElement.click();");
                        $browser->pause(1000)
                                ->press('تأكيد الحجز الآن');
                        
                        // STEP 6: Success Redirection
                        $browser->pause(2000)
                                ->assertSee('تم تأكيد طلب الحجز المبدئي');

            } catch (\Exception $e) {
                // CRITICAL REQUIREMENT: Physical screenshot on UI failure
                $browser->screenshot('failed_checkout_step');
                throw $e;
            }
        });
    }
}
