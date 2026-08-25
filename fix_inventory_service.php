<?php
$content = file_get_contents('app/Services/InventoryService.php');
$content = str_replace("'type' => 'hold_release',", "'type' => 'hold',", $content);
$content = str_replace("'type' => 'cancellation_release',", "'type' => 'cancelled',", $content);
$content = str_replace("\$type = \$seatDifference > 0 ? 'passenger_added' : 'passenger_removed';", "\$type = \$seatDifference > 0 ? 'confirmed' : 'cancelled';", $content);
$content = str_replace("'cancellation_release'", "'cancelled'", $content);
$content = str_replace("'hold_release'", "'hold'", $content);
file_put_contents('app/Services/InventoryService.php', $content);
