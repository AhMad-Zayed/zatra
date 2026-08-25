<?php
$content = file_get_contents('tests/Feature/BookingAndFinancialEngineTest.php');

$patch = <<<PATCH
        \$instance = TripInstance::create([
            'tenant_id' => \$tenant->id,
            'trip_template_id' => \$template->id,
            'start_date' => now()->addDays(1),
            'end_date' => now()->addDays(1),
            'available_seats' => 2,
            'status' => 'active',
        ]);
        
        \$cat = \App\Models\TripPassengerCategory::create([
            'tenant_id' => \$tenant->id,
            'trip_instance_id' => \$instance->id,
            'name' => 'Adult',
            'price' => 50.00,
            'requires_seat' => true,
        ]);
PATCH;

$content = preg_replace('/\$instance = TripInstance::create\(\[.*?\'status\' => \'active\',\s*\]\);/s', $patch, $content);
file_put_contents('tests/Feature/BookingAndFinancialEngineTest.php', $content);
