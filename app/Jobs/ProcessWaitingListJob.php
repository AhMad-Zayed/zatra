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

        // 2. Pop the oldest pending record (FIFO) that requested seats <= available seats
        $nextInLine = WaitingList::whereHas('tripInstances', function ($q) {
                $q->where('trip_instances.id', $this->tripInstance->id);
            })
            ->where('status', WaitingListStatusEnum::Pending)
            ->where('seats_requested', '<=', $this->tripInstance->available_seats)
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

        // NOTE: In the future, this notification will include the Magic Link (/b/{uuid}) 
        // to a temporary Booking so the customer can pay and confirm their seat within 15 minutes.
        if (in_array($channelPreference, ['whatsapp', 'both'])) {
            \App\Jobs\SendAtlahubWhatsAppJob::dispatch(
                $this->tripInstance->tenant_id,
                'waitlist',
                [
                    'phone_number' => $nextInLine->customer_phone,
                    'customer_name' => $nextInLine->customer_name,
                    'custom_attributes' => [
                        'waitlist_status' => 'notified',
                    ],
                    'template_variables' => [
                        $nextInLine->customer_name,
                        $this->tripInstance->tripTemplate->title,
                        $nextInLine->seats_requested,
                        $signedUrl
                    ]
                ]
            );
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
