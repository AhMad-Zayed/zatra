<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\AtlahubService;
use App\Models\Tenant;
use Illuminate\Support\Facades\Log;

class SendAtlahubWhatsAppJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tenantId;
    public $eventType;
    public $data;

    /**
     * Create a new job instance.
     * 
     * @param int $tenantId
     * @param string $eventType e.g., 'magic_link', 'ticket', 'waitlist'
     * @param array $data Contains customer_name, phone_number, custom_attributes, and template_variables.
     */
    public function __construct(int $tenantId, string $eventType, array $data)
    {
        $this->tenantId = $tenantId;
        $this->eventType = $eventType;
        $this->data = $data;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $tenant = Tenant::find($this->tenantId);
            
            if (!$tenant) {
                return;
            }

            // Check if WhatsApp alerts are globally enabled for this tenant
            if (!($tenant->enable_whatsapp_alerts ?? true)) {
                return;
            }

            $atlahubService = new AtlahubService($tenant);
            
            // 1. Sync Contact (Deduplication & Custom Attributes)
            $contactId = $atlahubService->syncContact(
                $this->data['customer_name'] ?? 'عميل',
                $this->data['phone_number'],
                $this->data['custom_attributes'] ?? []
            );

            if (!$contactId) {
                Log::error("SendAtlahubWhatsAppJob: Could not sync contact for phone: " . $this->data['phone_number']);
                return; // Graceful exit, don't crash
            }

            // 2. Map Event to Template Name
            // These should match the exact template names approved in Meta/Chatwoot
            $templateName = match($this->eventType) {
                'magic_link' => 'zatara_magic_link_v1',
                'ticket' => 'zatara_ticket_issued_v1',
                'waitlist' => 'zatara_waitlist_available_v1',
                default => null
            };

            if (!$templateName) {
                return;
            }

            // 3. Send the Template
            $atlahubService->sendTemplateMessage(
                $contactId,
                $templateName,
                $this->data['template_variables'] ?? [],
                'ar' // Default language
            );

        } catch (\Exception $e) {
            // Graceful Failure: Log the error but don't cause the system to crash
            Log::error("SendAtlahubWhatsAppJob Exception: " . $e->getMessage(), [
                'tenantId' => $this->tenantId,
                'eventType' => $this->eventType,
                'data' => $this->data
            ]);
        }
    }
}
