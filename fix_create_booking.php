<?php
$content = file_get_contents('app/Services/CreateBookingService.php');

$content = str_replace("while (Booking::where('pnr', \$pnr)->exists()) {", "\$pnr = 'ZTR-' . strtoupper(\Illuminate\Support\Str::random(6));\n            while (Booking::where('pnr', \$pnr)->exists()) {", $content);

file_put_contents('app/Services/CreateBookingService.php', $content);
