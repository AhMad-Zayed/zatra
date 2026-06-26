<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleAndPermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Create standard Shield permissions
        $permissions = [
            // Bookings
            'view_any_booking',
            'view_booking',
            'create_booking',
            'update_booking',
            'delete_booking',
            
            // Payments
            'view_any_payment',
            'view_payment',
            'create_payment',
            'update_payment',
            'delete_payment',
            
            // Trip Templates
            'view_any_trip::template',
            'view_trip::template',
            'create_trip::template',
            'update_trip::template',
            'delete_trip::template',
            
            // Trip Instances
            'view_any_trip::instance',
            'view_trip::instance',
            'create_trip::instance',
            'update_trip::instance',
            'delete_trip::instance',
            
            // Audit Logs
            'view_any_activity',
            'view_activity',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        // Define roles and assign permissions
        
        // Super Admin gets everything
        $superAdmin = Role::firstOrCreate(['name' => 'super_admin']);
        $superAdmin->syncPermissions(Permission::all());

        // Tenant Admin gets everything
        $admin = Role::firstOrCreate(['name' => 'admin']);
        $admin->syncPermissions(Permission::all());

        // Ops Manager
        $opsManager = Role::firstOrCreate(['name' => 'ops_manager']);
        $opsManager->syncPermissions([
            'view_any_trip::template',
            'view_trip::template',
            'create_trip::template',
            'update_trip::template',
            
            'view_any_trip::instance',
            'view_trip::instance',
            'create_trip::instance',
            'update_trip::instance',
            
            'view_any_booking',
            'view_booking',
        ]);

        // Booking Agent
        $bookingAgent = Role::firstOrCreate(['name' => 'booking_agent']);
        $bookingAgent->syncPermissions([
            'view_any_booking',
            'view_booking',
            'create_booking',
            'update_booking',
            
            'view_any_payment',
            'view_payment',
            'create_payment',
            
            'view_any_trip::template',
            'view_trip::template',
            
            'view_any_trip::instance',
            'view_trip::instance',
        ]);

        // Accountant
        $accountant = Role::firstOrCreate(['name' => 'accountant']);
        $accountant->syncPermissions([
            'view_any_booking',
            'view_booking',
            
            'view_any_payment',
            'view_payment',
            'create_payment',
            
            'view_any_trip::template',
            'view_trip::template',
            
            'view_any_trip::instance',
            'view_trip::instance',
        ]);

        // Guide
        $guide = Role::firstOrCreate(['name' => 'guide']);
        $guide->syncPermissions([
            'view_any_booking',
            'view_booking',
            'view_any_trip::instance',
            'view_trip::instance',
        ]);
    }
}
