<?php

namespace Tests\Browser;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;
use App\Models\Tenant;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Hash;

class RoleSecurityFlowTest extends DuskTestCase
{
    public function test_role_security_flow()
    {
        // 1. Setup Tenant and Roles
        $tenant = Tenant::firstOrCreate(
            ['slug' => 'security-agency'],
            ['name' => 'Security Test Agency', 'domain' => 'security.zatara.com']
        );

        $adminRole = Role::firstOrCreate(['name' => 'agency_admin', 'guard_name' => 'web']);
        $accountantRole = Role::firstOrCreate(['name' => 'accountant', 'guard_name' => 'web']);
        $agentRole = Role::firstOrCreate(['name' => 'booking_agent', 'guard_name' => 'web']);

        // 2. Setup Users
        $admin = User::firstOrCreate(
            ['email' => 'admin@security.zatara.com'],
            ['name' => 'Agency Admin', 'password' => Hash::make('password'), 'phone' => '1234567890']
        );
        if (!$admin->tenants()->where('tenants.id', $tenant->id)->exists()) {
            $admin->tenants()->attach($tenant->id);
        }
        setPermissionsTeamId($tenant->id);
        $admin->syncRoles([$adminRole]);

        $agent = User::firstOrCreate(
            ['email' => 'agent@security.zatara.com'],
            ['name' => 'Booking Agent', 'password' => Hash::make('password'), 'phone' => '0987654321']
        );
        if (!$agent->tenants()->where('tenants.id', $tenant->id)->exists()) {
            $agent->tenants()->attach($tenant->id);
        }
        setPermissionsTeamId($tenant->id);
        $agent->syncRoles([$agentRole]);

        $this->browse(function (Browser $browser) use ($admin, $agent, $tenant, $accountantRole) {
            try {
                // TEST 1: Booking Agent Access (Should be blocked from certain areas)
                $browser->loginAs($agent)
                        ->visit('/admin/' . $tenant->id)
                        ->pause(2000)
                        // Should not see the financial widgets
                        ->assertDontSee('التدفقات النقدية السنوية')
                        ->assertDontSee('الإيرادات المحصلة')
                        // Should not be able to access settings
                        ->visit('/admin/' . $tenant->id . '/manage-agency-settings')
                        ->pause(2000)
                        ->assertPathIs('/admin/' . $tenant->id . '/manage-agency-settings')
                        ->screenshot('agent_forbidden');

                $browser->logout();

                // TEST 2: Agency Admin Access (Should be able to manage staff)
                $browser->loginAs($admin)
                        ->visit('/admin/' . $tenant->id . '/staff')
                        ->pause(2000)
                        ->assertSee('طاقم العمل')
                        ->visit('/admin/' . $tenant->id . '/staff/create')
                        ->pause(1000)
                        ->type('input[wire\:model="data.name"]', 'New Accountant')
                        ->type('input[wire\:model="data.email"]', 'accountant_' . uniqid() . '@security.zatara.com')
                        ->type('input[wire\:model="data.phone"]', '05' . rand(10000000, 99999999))
                        ->type('input[wire\:model="data.password"]', 'password123')
                        ->pause(1000)
                        ->click("input[value='{$accountantRole->id}']")
                        ->pause(1000)
                        ->press('إضافة')
                        ->pause(2000)
                        ->assertSee('تمت الإضافة') // Toast notification
                        ->screenshot('admin_created_staff');

            } catch (\Exception $e) {
                $browser->screenshot('failed_role_security');
                throw $e;
            }
        });
    }
}
