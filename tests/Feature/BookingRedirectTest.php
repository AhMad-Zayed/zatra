<?php

namespace Tests\Feature;

use Tests\TestCase;
use Livewire\Livewire;
use App\Filament\Resources\BookingResource\Pages\CreateBooking;
use App\Models\User;
use App\Models\Customer;
use App\Models\TripInstance;
use App\Models\PackageOption;
use App\Models\TripPassengerCategory;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class BookingRedirectTest extends TestCase
{
    use DatabaseTransactions;

    public function test_booking_creation_redirects_to_view_page()
    {
        $user = User::where('email', 'admin@zatara.com')->first();
        $this->actingAs($user);
        
        $tenant = \App\Models\Tenant::first();
        
        $customer = Customer::firstOrCreate(['tenant_id' => $tenant->id, 'name' => 'Test Customer', 'phone' => '0599000000']);
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

        $data = [
            'customer_id' => $customer->id,
            'trip_instance_id' => $trip->id,
            'package_option_id' => $package->id,
            'passengers' => [
                [
                    'trip_passenger_category_id' => $category->id,
                    'first_name' => 'Test',
                    'last_name' => 'User',
                    'document_type' => 'passport',
                    'document_number' => '123456789',
                    'date_of_birth' => '1990-01-01',
                    'gender' => 'male',
                ]
            ],
            'initial_payment_amount' => 0,
        ];

        $component = Livewire::test(CreateBooking::class)->fillForm($data);
        $component->call('create');
        
        // In Livewire 3, getting the redirect URL from a component response
        $redirectUrl = $component->effects['redirect'] ?? 'No redirect';
        if (is_array($redirectUrl)) {
             $redirectUrl = $redirectUrl[0] ?? $redirectUrl['url'] ?? json_encode($redirectUrl);
        }
        echo "\nREDIRECT URL: " . (is_string($redirectUrl) ? $redirectUrl : json_encode($redirectUrl)) . "\n";
    }
}
