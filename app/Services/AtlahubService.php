<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AtlahubService
{
    protected string $apiUrl;
    protected string $accountId;
    protected string $inboxId;
    protected string $apiToken;

    /**
     * Initialize the service with the Tenant's Atlahub credentials.
     */
    public function __construct(Tenant $tenant)
    {
        $settings = $tenant->settings ?? [];
        
        $this->apiUrl = $settings['atlahub_api_url'] ?? config('services.atlahub.api_url', 'https://chat.atlahub.com');
        $this->accountId = $settings['atlahub_account_id'] ?? env('ATLAHUB_ACCOUNT_ID');
        $this->inboxId = $settings['atlahub_inbox_id'] ?? env('ATLAHUB_INBOX_ID');
        $this->apiToken = $settings['atlahub_api_token'] ?? env('ATLAHUB_API_TOKEN');
    }

    /**
     * Get the configured Http client with Headers.
     */
    protected function client()
    {
        return Http::withHeaders([
            'api_access_token' => $this->apiToken,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->baseUrl($this->apiUrl . '/api/v1/accounts/' . $this->accountId);
    }

    /**
     * Deduplication: Find or create contact, and update Custom Attributes.
     * 
     * @return int|null The Contact ID in Atlahub
     */
    public function syncContact(string $name, string $phone, array $customAttributes = []): ?int
    {
        if (empty($this->apiToken) || empty($this->accountId)) {
            Log::warning("AtlahubService: Credentials missing for Tenant.");
            return null;
        }

        // Format phone to international standard (E.164) if needed
        $formattedPhone = str_starts_with($phone, '+') ? $phone : '+' . ltrim($phone, '0');

        try {
            // 1. Search for existing contact by exact phone number
            $response = $this->client()->get('/contacts/search', [
                'q' => $formattedPhone
            ]);

            if ($response->successful()) {
                $payload = $response->json('payload');
                $contacts = $payload ?? [];

                if (count($contacts) > 0) {
                    $contactId = $contacts[0]['id'];
                    
                    // Update existing contact with latest custom attributes
                    $this->client()->put("/contacts/{$contactId}", [
                        'name' => $name, // Update name if changed
                        'custom_attributes' => $customAttributes
                    ]);

                    return $contactId;
                }
            }

            // 2. Create new contact if not found
            $createResponse = $this->client()->post('/contacts', [
                'inbox_id' => $this->inboxId,
                'name' => $name,
                'phone_number' => $formattedPhone,
                'custom_attributes' => $customAttributes
            ]);

            if ($createResponse->successful()) {
                return $createResponse->json('payload.contact.id');
            }

            Log::error("AtlahubService: Failed to create contact", ['response' => $createResponse->body()]);
            return null;

        } catch (\Exception $e) {
            Log::error("AtlahubService Exception in syncContact: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Send a WhatsApp Template Message via Chatwoot (Atlahub).
     * 
     * @param int $contactId The ID of the contact in Atlahub.
     * @param string $templateName The exact Meta template name.
     * @param array $templateVariables Array of strings representing variables {{1}}, {{2}}, etc.
     * @param string $language The Meta template language code (e.g. ar).
     */
    public function sendTemplateMessage(int $contactId, string $templateName, array $templateVariables = [], string $language = 'ar')
    {
        if (empty($this->apiToken) || empty($this->accountId) || empty($this->inboxId)) {
            return false;
        }

        // Build Meta Template Components (Body Parameters)
        $parameters = array_map(function ($variable) {
            return [
                'type' => 'text',
                'text' => (string) $variable
            ];
        }, $templateVariables);

        $templateParams = [
            'name' => $templateName,
            'language' => $language,
            'components' => []
        ];

        if (count($parameters) > 0) {
            $templateParams['components'][] = [
                'type' => 'body',
                'parameters' => $parameters
            ];
        }

        try {
            // Check for existing open conversations for this contact
            $conversationsResponse = $this->client()->get("/contacts/{$contactId}/conversations");
            
            $existingConversationId = null;
            
            if ($conversationsResponse->successful()) {
                $conversations = $conversationsResponse->json('payload') ?? [];
                
                foreach ($conversations as $conversation) {
                    if ($conversation['inbox_id'] == $this->inboxId && $conversation['status'] === 'open') {
                        $existingConversationId = $conversation['id'];
                        break;
                    }
                }
            }
            
            $messagePayload = [
                'content' => 'إشعار من زتارا (تم إرسال قالب)', // Fallback text for the inbox view
                'template_params' => $templateParams
            ];

            if ($existingConversationId) {
                // Send message to existing open conversation
                $response = $this->client()->post("/conversations/{$existingConversationId}/messages", $messagePayload);
            } else {
                // Create new conversation and send message
                $payload = [
                    'inbox_id' => (int) $this->inboxId,
                    'contact_id' => $contactId,
                    'message' => $messagePayload
                ];
                $response = $this->client()->post('/conversations', $payload);
            }

            if ($response->successful()) {
                return $response->json();
            }

            Log::error("AtlahubService: Failed to send template message", [
                'status' => $response->status(),
                'response' => $response->body(),
            ]);
            
            return false;

        } catch (\Exception $e) {
            Log::error("AtlahubService Exception in sendTemplateMessage: " . $e->getMessage());
            return false;
        }
    }
}
