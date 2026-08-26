<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MultiTenancyAndAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_belongs_to_multiple_tenants(): void
    {
        $tenant1 = Tenant::create(['name' => 'Agency West']);
        $tenant2 = Tenant::create(['name' => 'Agency East']);

        $user = User::create([
            'name' => 'Manager',
            'phone' => '+962791234567',
            'email' => 'manager@agency.com',
            'password' => 'secret',
        ]);

        $user->tenants()->attach([$tenant1->id, $tenant2->id]);

        $this->assertCount(2, $user->tenants);
        $this->assertTrue($user->canAccessTenant($tenant1));
        $this->assertTrue($user->canAccessTenant($tenant2));
    }

    // test_phone_number_normalization and test_silent_auth_find_or_create_customer removed:
    // both exercised App\Services\CustomerAuthService directly, which has been retired as part of
    // the Customer Portal Consolidation (superseded by CustomerOtpService, the live OTP path used
    // by the CustomerLogin Livewire component). The class no longer exists to test.
}
