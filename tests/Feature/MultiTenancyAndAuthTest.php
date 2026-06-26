<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\CustomerAuthService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MultiTenancyAndAuthTest extends TestCase
{
    use RefreshDatabase;

    private CustomerAuthService $authService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->authService = new CustomerAuthService();
    }

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

    public function test_phone_number_normalization(): void
    {
        $phone1 = '00962 79-123 4567';
        $phone2 = '+970 (59) 111-2222';

        $this->assertEquals('+962791234567', $this->authService->normalizePhone($phone1));
        $this->assertEquals('+970591112222', $this->authService->normalizePhone($phone2));
    }

    public function test_silent_auth_find_or_create_customer(): void
    {
        $tenant = Tenant::create(['name' => 'Test Agency']);

        // Set current Filament tenant scope manually for test
        Filament::setTenant($tenant, true);

        // 1. First auth call - should create a new user
        $customer1 = $this->authService->findOrCreateByPhone('00962 79 000 1111', 'Omar Customer');

        $this->assertDatabaseHas('users', [
            'name' => 'Omar Customer',
            'phone' => '+962790001111',
            'password' => null,
        ]);

        // Verify relationship via pivot table
        $this->assertTrue($customer1->canAccessTenant($tenant));

        // 2. Second auth call with same phone - should retrieve the existing user instead of creating a new one
        $customer2 = $this->authService->findOrCreateByPhone('+962790001111', 'Omar Duplicate Name');

        $this->assertEquals($customer1->id, $customer2->id);
        $this->assertEquals('Omar Customer', $customer2->name); // Name is not overwritten
    }
}
