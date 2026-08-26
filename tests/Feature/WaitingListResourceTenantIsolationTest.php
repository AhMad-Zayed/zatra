<?php

namespace Tests\Feature;

use App\Filament\Resources\WaitingListResource;
use App\Filament\Resources\WaitingListResource\Pages\CreateWaitingList;
use App\Models\Tenant;
use App\Models\TripInstance;
use App\Models\TripTemplate;
use Filament\Facades\Filament;
use Filament\Forms\Form;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CRITICAL HOTFIX regression coverage: WaitingListResource's destination-trip picker
 * (Forms\Components\Select::make('tripInstances')) queried TripInstance with zero tenant scope
 * -- TripInstance has no BelongsToTenant global scope, so a staff member at one tenant could see
 * (and select) trip instances belonging to OTHER tenants in this dropdown. A real cross-tenant
 * data leak, same CRITICAL-severity class as the original multi-tenant isolation audit.
 *
 * Follows the existing precedent in TripCancellationTest::
 * test_trip_instance_resource_bulk_status_change_excludes_cancelled for inspecting a Filament
 * field's real rendered ->getOptions() rather than trusting the source text alone.
 */
class WaitingListResourceTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_destination_trip_picker_only_shows_the_current_tenants_trips(): void
    {
        $tenantA = Tenant::create(['name' => 'Agency A', 'slug' => 'agency-a-wl-iso']);
        $tenantB = Tenant::create(['name' => 'Agency B', 'slug' => 'agency-b-wl-iso']);

        $templateA = TripTemplate::create(['tenant_id' => $tenantA->id, 'title' => 'Trip A', 'base_price' => 100]);
        $templateB = TripTemplate::create(['tenant_id' => $tenantB->id, 'title' => 'Trip B', 'base_price' => 100]);

        $instanceA = TripInstance::create([
            'tenant_id' => $tenantA->id,
            'trip_template_id' => $templateA->id,
            'start_date' => now()->addDays(5),
            'end_date' => now()->addDays(10),
            'available_seats' => 20,
            'status' => 'active',
        ]);
        $instanceB = TripInstance::create([
            'tenant_id' => $tenantB->id,
            'trip_template_id' => $templateB->id,
            'start_date' => now()->addDays(5),
            'end_date' => now()->addDays(10),
            'available_seats' => 20,
            'status' => 'active',
        ]);

        Filament::setTenant($tenantA, true);

        $page = new CreateWaitingList();
        $form = WaitingListResource::form(Form::make($page));

        $field = null;
        foreach ($form->getComponents() as $component) {
            if (method_exists($component, 'getName') && $component->getName() === 'tripInstances') {
                $field = $component;
            }
        }
        $this->assertNotNull($field, "Could not locate the 'tripInstances' field to inspect its options.");

        $options = $field->getOptions();

        $this->assertArrayHasKey($instanceA->id, $options, 'Tenant A must see its own trip instance in the picker.');
        $this->assertArrayNotHasKey($instanceB->id, $options, 'Tenant A must NOT see Tenant B\'s trip instance in the picker -- cross-tenant leak.');
    }
}
