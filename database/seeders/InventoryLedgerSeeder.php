<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\TripInstance;
use App\Models\InventoryLedger;

class InventoryLedgerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $instances = TripInstance::whereNotNull('available_seats')->get();
        foreach ($instances as $instance) {
            if ($instance->available_seats > 0) {
                InventoryLedger::firstOrCreate([
                    'trip_instance_id' => $instance->id,
                    'type' => 'initial_stock',
                ], [
                    'quantity' => $instance->available_seats,
                ]);
            }
        }
    }
}
