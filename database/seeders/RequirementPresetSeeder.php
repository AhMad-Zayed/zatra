<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\RequirementPreset;
use App\Models\Tenant;

class RequirementPresetSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $tenant = Tenant::first();
        if (!$tenant) return;

        RequirementPreset::firstOrCreate([
            'tenant_id' => $tenant->id,
            'title' => 'Standard Schengen',
        ], [
            'items' => [
                ['name' => 'Passport Copy', 'type' => 'image', 'is_required' => true],
                ['name' => 'Bank Statement (6 Months)', 'type' => 'image', 'is_required' => true],
                ['name' => 'HR Letter', 'type' => 'image', 'is_required' => true],
                ['name' => 'Passport Issue Date', 'type' => 'date', 'is_required' => true],
                ['name' => 'Passport Expiry Date', 'type' => 'date', 'is_required' => true],
            ]
        ]);

        RequirementPreset::firstOrCreate([
            'tenant_id' => $tenant->id,
            'title' => 'Standard UAE',
        ], [
            'items' => [
                ['name' => 'Passport Copy', 'type' => 'image', 'is_required' => true],
                ['name' => 'Personal Photo (White Background)', 'type' => 'image', 'is_required' => true],
                ['name' => 'Passport Expiry Date', 'type' => 'date', 'is_required' => true],
            ]
        ]);

        RequirementPreset::firstOrCreate([
            'tenant_id' => $tenant->id,
            'title' => 'Internal Flight',
        ], [
            'items' => [
                ['name' => 'National ID / Iqama', 'type' => 'image', 'is_required' => true],
                ['name' => 'Full Name (As in ID)', 'type' => 'text', 'is_required' => true],
            ]
        ]);
    }
}
