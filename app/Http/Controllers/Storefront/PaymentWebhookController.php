<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Tenant;
use App\Services\Payments\PaymentManager;
use App\Services\Payments\ProcessGatewayPaymentService;

class PaymentWebhookController extends Controller
{
    /**
     * Handle incoming webhooks from payment gateways.
     * The gateway name is passed in the URL (e.g., /api/webhooks/paytabs).
     * The tenant and booking context MUST be extracted from the payload/metadata.
     */
    public function handleCallback(Request $request, string $gatewayName, ProcessGatewayPaymentService $paymentService)
    {
        // 1. We must extract the tenant ID from the payload (injected during initializePayment)
        // Since gateways vary, we rely on the specific Gateway Adapter to do this safely.
        // For architectural purity in the controller, let's assume the Gateway Manager can resolve the Tenant from the payload.
        // Alternatively, if the webhook URL is per-tenant (e.g., /t/{tenant}/webhooks/{gateway}), 
        // we'd use route-model binding. But following generic gateway rules:
        
        $tenantId = $request->input('metadata.tenant_id') ?? $request->input('custom_ref');
        
        if (!$tenantId) {
            abort(400, 'Tenant context missing from gateway payload.');
        }

        $tenant = Tenant::findOrFail($tenantId);

        // 2. Resolve the Gateway Adapter
        $gateway = PaymentManager::resolve($gatewayName, $tenant);

        // 3. Mathematical & Cryptographic Verification
        if (!$gateway->verifyCallback($request)) {
            abort(403, 'Invalid payment signature or payload verification failed.');
        }

        // 4. Extract Standardized Payment DTO
        $paymentData = $gateway->processPayment($request);

        // 5. Delegate to the Financial Service (DDD Domain)
        $payment = $paymentService->execute($paymentData);

        if ($payment === null) {
            // Idempotent Skip - Already Processed
            return response()->json(['status' => 'Idempotent skip'], 200);
        }

        return response()->json(['status' => 'Success'], 200);
    }
}
