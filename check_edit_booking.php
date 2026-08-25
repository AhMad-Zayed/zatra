<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "Content of EditBooking:\n";
echo file_get_contents('app/Filament/Resources/BookingResource/Pages/EditBooking.php');
