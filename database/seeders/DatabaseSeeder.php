<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Models\Tenant;
use App\Models\PassengerCategory;
use App\Models\TemplatePassengerCategory;
use App\Models\TripPassengerCategory;
use App\Models\TripTemplate;
use App\Models\TripInstance;
use App\Models\Customer;
use App\Models\Booking;
use App\Models\Passenger;
use App\Models\Payment;
use App\Models\WaitingList;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Enums\TripStatusEnum;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Roles
        $adminRole = Role::firstOrCreate(['name' => 'agency_admin', 'guard_name' => 'web']);
        $agentRole = Role::firstOrCreate(['name' => 'booking_agent', 'guard_name' => 'web']);

        // 2. Tenant
        $tenant = Tenant::firstOrCreate(
            ['slug' => 'zatara'],
            [
                'name' => 'Zatara Tourism',
                'enable_whatsapp_alerts' => true,
                'enable_email_alerts' => true,
            ]
        );

        // 3. Users
        setPermissionsTeamId($tenant->id);
        
        $adminUser = User::firstOrCreate(
            ['email' => 'admin@zatara.com'],
            [
                'name' => 'Agency Admin',
                'phone' => '0500000001',
                'password' => Hash::make('password'),
            ]
        );
        $adminUser->tenants()->syncWithoutDetaching([$tenant->id]);
        $adminUser->assignRole($adminRole);

        $agentUser = User::firstOrCreate(
            ['email' => 'agent@zatara.com'],
            [
                'name' => 'Booking Agent',
                'phone' => '0500000002',
                'password' => Hash::make('password'),
            ]
        );
        $agentUser->tenants()->syncWithoutDetaching([$tenant->id]);
        $agentUser->assignRole($agentRole);

        // 4. Passenger Categories (DDD Refactored)
        $adultCat = PassengerCategory::firstOrCreate(['tenant_id' => $tenant->id, 'name' => 'بالغ (Adult)']);
        $childCat = PassengerCategory::firstOrCreate(['tenant_id' => $tenant->id, 'name' => 'طفل (Child)']);
        $infantCat = PassengerCategory::firstOrCreate(['tenant_id' => $tenant->id, 'name' => 'رضيع (Infant)']);

        // 5. Trip Template
        $template = TripTemplate::firstOrCreate(
            ['tenant_id' => $tenant->id, 'title' => 'Maldives Luxury Package'],
            [
                'description' => 'A luxurious 5-night stay in the Maldives.',
                'base_price' => 0,
            ]
        );

        // Template Categories
        TemplatePassengerCategory::firstOrCreate(['tenant_id' => $tenant->id, 'trip_template_id' => $template->id, 'passenger_category_id' => $adultCat->id], ['name' => 'بالغ (Adult)', 'price' => 5000]);
        TemplatePassengerCategory::firstOrCreate(['tenant_id' => $tenant->id, 'trip_template_id' => $template->id, 'passenger_category_id' => $childCat->id], ['name' => 'طفل (Child)', 'price' => 3000]);

        // 6. Trip Instances (1 Active, 1 Closed)
        $activeTrip = TripInstance::firstOrCreate(
            ['tenant_id' => $tenant->id, 'trip_template_id' => $template->id, 'status' => TripStatusEnum::Active->value],
            [
                'start_date' => now()->addDays(10),
                'end_date' => now()->addDays(15),
                'available_seats' => 20,
            ]
        );
        $activeAdultTier = TripPassengerCategory::firstOrCreate(['tenant_id' => $tenant->id, 'trip_instance_id' => $activeTrip->id, 'name' => 'بالغ (Adult)'], ['price' => 5000]);

        $closedTrip = TripInstance::firstOrCreate(
            ['tenant_id' => $tenant->id, 'trip_template_id' => $template->id, 'status' => TripStatusEnum::Closed->value],
            [
                'start_date' => now()->subDays(10),
                'end_date' => now()->subDays(5),
                'available_seats' => 20,
            ]
        );
        $closedAdultTier = TripPassengerCategory::firstOrCreate(['tenant_id' => $tenant->id, 'trip_instance_id' => $closedTrip->id, 'name' => 'بالغ (Adult)'], ['price' => 5000]);

        // 7. Customers
        $customer1 = Customer::firstOrCreate(['tenant_id' => $tenant->id, 'phone' => '0555555551'], ['name' => 'Ahmed (Paid)']);
        $customer2 = Customer::firstOrCreate(['tenant_id' => $tenant->id, 'phone' => '0555555552'], ['name' => 'Khalid (Balance Due)']);

        // 8. Bookings
        // Fully Paid Booking
        $booking1 = Booking::firstOrCreate(
            ['pnr' => 'ZAT-PAID-001'],
            [
                'tenant_id' => $tenant->id,
                'trip_instance_id' => $activeTrip->id,
                'user_id' => $agentUser->id,
                'customer_id' => $customer1->id,
                'booking_status' => BookingStatus::Confirmed,
                'payment_status' => PaymentStatus::Paid,
                'grand_total' => 5000,
                'total_paid' => 5000,
                'balance_due' => 0,
            ]
        );
        Passenger::firstOrCreate([
            'tenant_id' => $tenant->id, 'booking_id' => $booking1->id, 'trip_passenger_category_id' => $activeAdultTier->id,
            'first_name' => 'Ahmed', 'last_name' => 'Al-Paid', 'document_type' => 'passport', 'document_number' => 'P1234567'
        ]);
        Payment::firstOrCreate([
            'tenant_id' => $tenant->id, 'booking_id' => $booking1->id, 'amount' => 500000, 'payment_method' => 'cash'
        ], ['created_at' => now()]);

        // Balance Due Booking
        $booking2 = Booking::firstOrCreate(
            ['pnr' => 'ZAT-PEND-002'],
            [
                'tenant_id' => $tenant->id,
                'trip_instance_id' => $activeTrip->id,
                'user_id' => $agentUser->id,
                'customer_id' => $customer2->id,
                'booking_status' => BookingStatus::Pending,
                'payment_status' => PaymentStatus::PartiallyPaid,
                'grand_total' => 5000,
                'total_paid' => 2000,
                'balance_due' => 3000,
            ]
        );
        Passenger::firstOrCreate([
            'tenant_id' => $tenant->id, 'booking_id' => $booking2->id, 'trip_passenger_category_id' => $activeAdultTier->id,
            'first_name' => 'Khalid', 'last_name' => 'Al-Pending', 'document_type' => 'id_card', 'document_number' => '1002003004'
        ]);
        Payment::firstOrCreate([
            'tenant_id' => $tenant->id, 'booking_id' => $booking2->id, 'amount' => 200000, 'payment_method' => 'bank_transfer'
        ], ['created_at' => now()]);

        // 9. Waiting List
        WaitingList::firstOrCreate([
            'tenant_id' => $tenant->id, 'trip_instance_id' => $activeTrip->id, 'phone_number' => '0501234567'
        ], ['customer_name' => 'VIP Waiter 1', 'status' => 'pending']);
        
        WaitingList::firstOrCreate([
            'tenant_id' => $tenant->id, 'trip_instance_id' => $activeTrip->id, 'phone_number' => '0509876543'
        ], ['customer_name' => 'VIP Waiter 2', 'status' => 'pending']);

    }
}
