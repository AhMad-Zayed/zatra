<?php

namespace App\Jobs;

use App\Models\TripInstance;
use App\Models\WaitingList;
use App\Enums\WaitingListStatusEnum;
use Illuminate\Support\Facades\URL;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class ProcessWaitingListJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public $afterCommit = true;

    public function __construct(public TripInstance $tripInstance) {}

    public function handle(): void
    {
        // 1. Double check available seats
        if ($this->tripInstance->available_seats <= 0) {
            return;
        }

        // 2. Pop the oldest pending record (FIFO)
        $nextInLine = WaitingList::where('trip_instance_id', $this->tripInstance->id)
            ->where('status', WaitingListStatusEnum::Pending)
            ->oldest('created_at')
            ->first();

        if (!$nextInLine) {
            return; // Queue is empty
        }

        // 3. Generate 2-Hour Signed Route
        $signedUrl = URL::temporarySignedRoute(
            'waiting-list.redeem',
            now()->addHours(2),
            ['waitingList' => $nextInLine->id]
        );

        // 4. Update Status FIRST to prevent race conditions
        $nextInLine->update([
            'status' => WaitingListStatusEnum::Notified,
            'notified_at' => now(),
        ]);

        // 5. Omni-Channel Routing Logic based on Tenant Settings
        $channelPreference = $this->tripInstance->tenant->settings['waiting_list_channel'] ?? 'both';

        if (in_array($channelPreference, ['whatsapp', 'both'])) {
            // Assume WhatsAppService exists or will exist to handle this
            if (class_exists(\App\Services\WhatsAppService::class)) {
                app(\App\Services\WhatsAppService::class)->sendWaitingListAlert(
                    $nextInLine->phone_number,
                    $nextInLine->customer_name,
                    $signedUrl
                );
            }
        }

        if (in_array($channelPreference, ['email', 'both']) && $nextInLine->customer_email) {
            // Send email
            if (class_exists(\App\Mail\WaitingListAlertMail::class)) {
                \Illuminate\Support\Facades\Mail::to($nextInLine->customer_email)
                    ->send(new \App\Mail\WaitingListAlertMail($nextInLine, $signedUrl));
            }
        }
    }
}
