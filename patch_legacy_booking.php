<?php
$content = file_get_contents('app/Services/BookingService.php');

$patch = <<<PATCH
                \$catId = \$px['trip_passenger_category_id'] ?? \App\Models\TripPassengerCategory::where('trip_instance_id', \$instance->id)->first()->id ?? 1;
                \$passenger = \$booking->passengers()->create([
                    'tenant_id' => \$instance->tenant_id,
                    'trip_passenger_category_id' => \$catId,
                    'price_at_booking' => 0,
                    'first_name' => \$px['name'] ?? \$px['first_name'] ?? null,
                    'last_name' => \$px['last_name'] ?? null,
                    'document_number' => \$px['passport_number'] ?? \$px['document_number'] ?? null,
                ]);
PATCH;

$content = preg_replace('/\$passenger = \$booking->passengers\(\)->create\(\[\s*\'tenant_id\' => \$instance->tenant_id,\s*\'first_name\' => \$px\[\'name\'\] \?\? \$px\[\'first_name\'\] \?\? null,\s*\'last_name\' => \$px\[\'last_name\'\] \?\? null,\s*\'document_number\' => \$px\[\'passport_number\'\] \?\? \$px\[\'document_number\'\] \?\? null,\s*\]\);/s', $patch, $content);
file_put_contents('app/Services/BookingService.php', $content);
