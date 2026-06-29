<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\Booking;
use App\Services\Notifications\NotificationManager;
use Throwable;

class SendBookingNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // QA PATCH: Job Reliability Constraints
    public $tries = 3;
    public $backoff = [60, 300, 600];

    public function __construct(
        public Booking $booking,
        public string $channel,
        public string $message,
        public array $attachments = []
    ) {}

    public function handle(): void
    {
        $driver = NotificationManager::resolve($this->channel);
        $driver->send($this->booking, $this->message, $this->attachments);
    }

    /**
     * Handle a job failure.
     */
    public function failed(Throwable $exception): void
    {
        \Illuminate\Support\Facades\Log::error("Notification Job Failed (Channel: {$this->channel}, Booking: {$this->booking->id}): " . $exception->getMessage());
    }
}
