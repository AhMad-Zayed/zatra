<?php
$content = file_get_contents('app/Observers/BookingObserver.php');

$updateLogic = <<<UPDATE
    public function updated(Booking \$booking): void
    {
        if (\$booking->wasChanged('booking_status') && \$booking->booking_status === BookingStatus::Cancelled) {
            app(\App\Services\InventoryService::class)->releaseForCancellation(\$booking);
            \App\Jobs\WaitlistAutoPromotion::dispatch(\$booking->trip_instance_id);
        }
    }
UPDATE;

$content = preg_replace('/public function updated\(Booking \$booking\): void.*?\}\n    \}/s', $updateLogic . "\n}", $content);

file_put_contents('app/Observers/BookingObserver.php', $content);
echo "Patched BookingObserver.php\n";
