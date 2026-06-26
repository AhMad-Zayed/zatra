<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\TripTemplate;
use App\Models\TripInstance;
use App\Models\Booking;
use App\Enums\BookingStatus;
use App\Services\BookingService;
use App\Services\PaymentService;
use App\Filament\Widgets\StatsOverviewWidget;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private BookingService $bookingService;
    private PaymentService $paymentService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bookingService = new BookingService();
        $this->paymentService = new PaymentService();
    }

    public function test_stats_widget_computes_correct_sums_scoped_to_tenant(): void
    {
        // 1. Create Tenant A with bookings
        $tenantA = Tenant::create(['name' => 'Agency North']);
        $customerA = User::create(['name' => 'Customer A', 'phone' => '0799999991']);
        $agentA = User::create(['name' => 'Agent A', 'phone' => '0791111111']);
        $agentA->tenants()->attach($tenantA);

        $templateA = TripTemplate::create([
            'tenant_id' => $tenantA->id,
            'title' => 'Trip A',
            'base_price' => 150.00,
        ]);
        $instanceA = TripInstance::create([
            'tenant_id' => $tenantA->id,
            'trip_template_id' => $templateA->id,
            'start_date' => now()->addDays(2),
            'end_date' => now()->addDays(3),
            'available_seats' => 20,
            'status' => 'active',
        ]);

        // Create booking: total 150.00
        $bookingA = $this->bookingService->createBooking($instanceA, $customerA, [
            ['name' => 'Passenger A1', 'passport_number' => 'PA111']
        ], 150.00);

        // Record partial payment: 50.00
        $this->paymentService->recordPayment($bookingA, 50.00, 'cash', $agentA, \App\Enums\PaymentType::DEPOSIT);

        // 2. Create Tenant B with bookings (isolation check)
        $tenantB = Tenant::create(['name' => 'Zatara Tours']);
        $customerB = User::create(['name' => 'Customer B', 'phone' => '0799999992']);
        $agentB = User::create(['name' => 'Agent B', 'phone' => '0792222222']);
        $agentB->tenants()->attach($tenantB);

        $templateB = TripTemplate::create([
            'tenant_id' => $tenantB->id,
            'title' => 'Trip B',
            'base_price' => 300.00,
        ]);
        $instanceB = TripInstance::create([
            'tenant_id' => $tenantB->id,
            'trip_template_id' => $templateB->id,
            'start_date' => now()->addDays(2),
            'end_date' => now()->addDays(3),
            'available_seats' => 20,
            'status' => 'active',
        ]);

        // Create booking: total 300.00
        $bookingB = $this->bookingService->createBooking($instanceB, $customerB, [
            ['name' => 'Passenger B1', 'passport_number' => 'PB111']
        ], 300.00);

        // Record payment: 300.00
        $this->paymentService->recordPayment($bookingB, 300.00, 'cash', $agentB, \App\Enums\PaymentType::FULL);

        // Set Filament context to Tenant A
        Filament::setTenant($tenantA, true);
        $this->actingAs($agentA);

        // Test Livewire StatsWidget on Tenant A
        Livewire::test(StatsOverviewWidget::class)
            ->assertSee('إجمالي المبيعات')
            ->assertSee('$150.00') // Tenant A Sales
            ->assertSee('$50.00')  // Tenant A Collected
            ->assertSee('$100.00') // Tenant A Remaining (150 - 50)
            ->assertDontSee('$300.00'); // Should not see Tenant B sales or collected

        // Set Filament context to Tenant B
        Filament::setTenant($tenantB, true);
        $this->actingAs($agentB);

        // Test Livewire StatsWidget on Tenant B
        Livewire::test(StatsOverviewWidget::class)
            ->assertSee('إجمالي المبيعات')
            ->assertSee('$300.00') // Tenant B Sales
            ->assertSee('$300.00') // Tenant B Collected
            ->assertSee('$0.00')   // Tenant B Remaining (300 - 300)
            ->assertDontSee('$150.00')
            ->assertDontSee('$50.00');
    }
}
