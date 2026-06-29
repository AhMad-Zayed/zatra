<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Tenant;
use App\Models\TripTemplate;
use App\Models\TripInstance;
use App\Models\Customer;
use App\Models\Passenger;
use App\Models\PassengerCategory;
use App\Models\GlobalAddon;
use App\Models\TemplatePassengerCategory;
use App\Models\TemplateAddon;
use App\Models\TripPassengerCategory;
use App\Models\TripAddon;
use App\Models\Payment;
use App\Services\CreateBookingService;
use Carbon\Carbon;
use Illuminate\Support\Str;

class HeavyLoadSimulationSeeder extends Seeder
{
    public function run()
    {
        // 1. Create Tenant if none
        $tenant = Tenant::firstOrCreate([
            'slug' => 'zatara'
        ], [
            'name' => 'Zatara Tours',
            'domain' => 'zatara.localhost',
            'is_active' => true,
        ]);

        // Need to run other core seeders to get base data if we wiped
        $this->call([
            RoleAndPermissionSeeder::class, // Just to make sure roles exist
        ]);

        // Ensure we have a Super Admin
        $ceo = \App\Models\User::firstOrCreate([
            'email' => 'ceo@zatara.com',
        ], [
            'name' => 'CEO',
            'phone' => '0599999999',
            'password' => bcrypt('password'),
            'is_super_admin' => true,
        ]);
        if (!$ceo->tenants()->where('tenant_id', $tenant->id)->exists()) {
            $ceo->tenants()->attach($tenant->id);
        }

        $this->command->info('Creating 20 Trip Instances with Media...');

        // 2. Create 20 Trip Templates & Instances
        $instances = [];
        for ($i = 1; $i <= 20; $i++) {
            $template = TripTemplate::create([
                'tenant_id' => $tenant->id,
                'title' => 'Luxurious Trip to Destination ' . $i,
                'description' => 'A wonderful experience featuring rich text and amazing amenities.',
                'base_price' => rand(1000, 5000),
            ]);

            // Add Spatie Media from Picsum
            $template->addMediaFromUrl("https://picsum.photos/800/600?random={$i}")
                     ->toMediaCollection('images');

            // Global Passenger Categories & Template assignment
            $adultCat = PassengerCategory::firstOrCreate(['tenant_id' => $tenant->id, 'name' => 'Adult']);
            $childCat = PassengerCategory::firstOrCreate(['tenant_id' => $tenant->id, 'name' => 'Child']);
            
            $tplAdult = TemplatePassengerCategory::create([
                'tenant_id' => $tenant->id,
                'trip_template_id' => $template->id,
                'passenger_category_id' => $adultCat->id,
                'name' => 'Adult',
                'price' => rand(1000, 5000)
            ]);
            
            $tplChild = TemplatePassengerCategory::create([
                'tenant_id' => $tenant->id,
                'trip_template_id' => $template->id,
                'passenger_category_id' => $childCat->id,
                'name' => 'Child',
                'price' => rand(500, 2000)
            ]);

            // Global Addon & Template assignment
            $globalAddon = GlobalAddon::firstOrCreate([
                'tenant_id' => $tenant->id,
                'name' => 'VIP Airport Transfer'
            ]);
            
            $tplAddon = TemplateAddon::create([
                'tenant_id' => $tenant->id,
                'trip_template_id' => $template->id,
                'global_addon_id' => $globalAddon->id,
                'name' => $globalAddon->name,
                'price' => rand(200, 500),
            ]);

            // Instance
            $instance = TripInstance::create([
                'tenant_id' => $tenant->id,
                'trip_template_id' => $template->id,
                'start_date' => Carbon::now()->addDays(rand(10, 60)),
                'end_date' => Carbon::now()->addDays(rand(70, 90)),
                'available_seats' => rand(30, 50),
                'status' => 'active',
            ]);

            // Sync TripPassengerCategory and TripAddon
            $tripAdultCat = TripPassengerCategory::create([
                'tenant_id' => $tenant->id,
                'trip_instance_id' => $instance->id,
                'name' => 'Adult',
                'price' => $tplAdult->price,
            ]);
            
            $tripChildCat = TripPassengerCategory::create([
                'tenant_id' => $tenant->id,
                'trip_instance_id' => $instance->id,
                'name' => 'Child',
                'price' => $tplChild->price,
            ]);

            $tripAddon = TripAddon::create([
                'tenant_id' => $tenant->id,
                'trip_instance_id' => $instance->id,
                'name' => $globalAddon->name,
                'price' => $tplAddon->price,
            ]);

            $instances[] = $instance;
        }

        $this->command->info('Creating 100 Customers and Bookings...');

        // 3. Create 100 Customers and Bookings
        $bookings = [];
        $createBookingService = app(CreateBookingService::class);

        for ($j = 1; $j <= 100; $j++) {
            $customer = Customer::create([
                'tenant_id' => $tenant->id,
                'name' => 'Customer ' . $j,
                'email' => "customer{$j}@example.com",
                'phone' => '05' . rand(10000000, 99999999),
            ]);

            $instance = $instances[array_rand($instances)];
            $adultTier = TripPassengerCategory::where('trip_instance_id', $instance->id)->where('name', 'Adult')->first();
            $childTier = TripPassengerCategory::where('trip_instance_id', $instance->id)->where('name', 'Child')->first();
            $addon = TripAddon::where('trip_instance_id', $instance->id)->first();

            // Passengers array
            $passengers = [];
            
            // Adult
            $passengers[] = [
                'first_name' => 'Adult',
                'last_name' => 'Passenger',
                'date_of_birth' => '1990-01-01',
                'document_type' => 'passport',
                'document_number' => 'A' . rand(100000, 999999),
                'trip_passenger_category_id' => $adultTier->id,
            ];

            // Sometimes add a child
            if (rand(0, 1)) {
                $passengers[] = [
                    'first_name' => 'Child',
                    'last_name' => 'Passenger',
                    'date_of_birth' => Carbon::now()->subYears(8)->format('Y-m-d'),
                    'document_type' => 'passport',
                    'document_number' => 'C' . rand(100000, 999999),
                    'trip_passenger_category_id' => $childTier->id,
                ];
            }
            
            $addonsData = [];
            if (rand(0, 1)) {
                $addonsData[] = [
                    'trip_addon_id' => $addon->id,
                    'quantity' => 1
                ];
            }

            // Using CreateBookingService
            try {
                $booking = $createBookingService->execute([
                    'tenant_id' => $tenant->id,
                    'trip_instance_id' => $instance->id,
                    'customer_id' => $customer->id,
                    'passengersData' => $passengers,
                    'addonsData' => $addonsData,
                    'notes' => 'Stress test booking',
                ]);
                
                // Partially or Fully pay some bookings
                if (rand(0, 1)) {
                    $amount = rand(0, 1) ? $booking->grand_total : $booking->grand_total / 2;
                    Payment::create([
                        'tenant_id' => $tenant->id,
                        'booking_id' => $booking->id,
                        'amount' => $amount,
                        'payment_method' => 'bank_transfer',
                        'transaction_id' => 'TXN' . rand(1000, 9999),
                        'status' => 'completed'
                    ]);
                    // Let the observer or manual code recalculate
                    app(\App\Services\BookingService::class)->recalculateFinancialStatus($booking);
                }

                $bookings[] = $booking;
            } catch (\Exception $e) {
                // Ignore instances that might run out of seats
            }
        }

        $this->command->info('Cancelling 30 Bookings to trigger FSM & Waitlists...');

        // 4. Cancel 30 Bookings
        $toCancel = collect($bookings)->take(30);
        $oldBookingService = app(\App\Services\BookingService::class);
        foreach ($toCancel as $booking) {
            try {
                $oldBookingService->cancelBooking($booking, 'Mass test cancellation');
            } catch (\Exception $e) {
                // Ignore
            }
        }

        // Just to be absolutely sure the Customer 1 exists for Dusk
        $duskCustomer = Customer::firstOrCreate([
            'email' => 'dusk@zatara.com',
        ], [
            'tenant_id' => $tenant->id,
            'name' => 'Dusk Tester',
            'phone' => '0500000000',
        ]);
        
        $duskInstance = $instances[0];
        $createBookingService->execute([
            'tenant_id' => $tenant->id,
            'trip_instance_id' => $duskInstance->id,
            'customer_id' => $duskCustomer->id,
            'passengersData' => [
                [
                    'first_name' => 'Dusk',
                    'last_name' => 'Passenger',
                    'date_of_birth' => '1990-01-01',
                    'document_type' => 'passport',
                    'document_number' => 'A1234567',
                    'trip_passenger_category_id' => TripPassengerCategory::where('trip_instance_id', $duskInstance->id)->first()->id,
                ]
            ],
            'addonsData' => [],
            'notes' => 'Dusk visual test booking'
        ]);

        $this->command->info('Heavy Load Simulation Complete!');
    }
}
