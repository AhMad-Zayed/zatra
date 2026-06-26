<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Run roles and permissions seeder
        $this->call(RoleAndPermissionSeeder::class);

        // Create a default tenant
        $tenant = Tenant::firstOrCreate([
            'name' => 'Zatara Tourism',
            'domain' => 'zatara.localhost',
            'is_visa_enabled' => true,
        ]);

        // Create Super Admin user
        $superAdmin = User::firstOrCreate(
            ['phone' => '0790000000'],
            [
                'name' => 'Ahmad Super Admin',
                'email' => 'admin@zatara.com',
                'password' => Hash::make('ahmad123'),
            ]
        );
        $superAdmin->assignRole('super_admin');
        $superAdmin->tenants()->syncWithoutDetaching([$tenant->id]);

        // Create Booking Agent user
        $agent = User::firstOrCreate(
            ['phone' => '0791111111'],
            [
                'name' => 'Samer Booking Agent',
                'email' => 'agent@zatara.com',
                'password' => Hash::make('ahmad123'),
            ]
        );
        $agent->assignRole('booking_agent');
        $agent->tenants()->syncWithoutDetaching([$tenant->id]);

        // Create Accountant user
        $accountant = User::firstOrCreate(
            ['phone' => '0792222222'],
            [
                'name' => 'Rania Accountant',
                'email' => 'accountant@zatara.com',
                'password' => Hash::make('ahmad123'),
            ]
        );
        $accountant->assignRole('accountant');
        $accountant->tenants()->syncWithoutDetaching([$tenant->id]);

        // Create a test Customer (Silent Auth - no password)
        $customer = User::firstOrCreate(
            ['phone' => '0793333333'],
            [
                'name' => 'Tareq Customer',
                'email' => 'tareq@example.com',
                'password' => null,
            ]
        );
        $customer->tenants()->syncWithoutDetaching([$tenant->id]);

        // Seed Trip Templates
        $deadSeaTemplate = \App\Models\TripTemplate::firstOrCreate(
            ['title' => 'رحلة البحر الميت والاسترخاء'],
            [
                'tenant_id' => $tenant->id,
                'description' => 'رحلة عائلية مميزة ليوم كامل شاملة المواصلات ووجبة الغداء في منتجع 5 نجوم مع الاستمتاع بفوائد مياه البحر الميت الطينية.',
                'base_price' => 50.00,
            ]
        );

        $petraTemplate = \App\Models\TripTemplate::firstOrCreate(
            ['title' => 'رحلة استكشاف البتراء ووادي رم'],
            [
                'tenant_id' => $tenant->id,
                'description' => 'رحلة مغامرات شيقة لمدة يومين تشمل زيارة المدينة الوردية المفقودة (البتراء) والمبيت في مخيم بدوي فاخر في صحراء وادي رم مع جولة سيارات دفع رباعي.',
                'base_price' => 120.00,
            ]
        );

        // Seed Trip Instances (active departures in the future)
        \App\Models\TripInstance::firstOrCreate(
            [
                'tenant_id' => $tenant->id,
                'trip_template_id' => $deadSeaTemplate->id,
                'start_date' => now()->addDays(2)->format('Y-m-d'),
            ],
            [
                'end_date' => now()->addDays(2)->format('Y-m-d'),
                'available_seats' => 15,
                'status' => 'active',
            ]
        );

        \App\Models\TripInstance::firstOrCreate(
            [
                'tenant_id' => $tenant->id,
                'trip_template_id' => $petraTemplate->id,
                'start_date' => now()->addDays(5)->format('Y-m-d'),
            ],
            [
                'end_date' => now()->addDays(7)->format('Y-m-d'),
                'available_seats' => 10,
                'status' => 'active',
            ]
        );
    }
}
