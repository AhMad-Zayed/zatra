<?php

namespace App\Services\Payments\Gateways;

use App\Contracts\PaymentGatewayInterface;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Stripe\Stripe;
use Stripe\Checkout\Session;
use Stripe\Webhook;

class StripeGateway implements PaymentGatewayInterface
{
    protected Tenant $tenant;

    public function __construct(Tenant $tenant)
    {
        $this->tenant = $tenant;
        // 1. Config Cache Patch: Pull dynamically from the tenant's settings column
        $stripeSecret = $this->tenant->settings['stripe_secret'] ?? env('STRIPE_SECRET_KEY');
        Stripe::setApiKey($stripeSecret);
    }

    public function initializePayment(Booking $booking, float $amount): array
    {
        $session = Session::create([
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => $this->tenant->settings['currency'] ?? 'usd',
                    'unit_amount' => (int) ($amount * 100),
                    'product_data' => [
                        'name' => 'Booking #' . $booking->id,
                    ],
                ],
                'quantity' => 1,
            ]],
            'mode' => 'payment',
            'success_url' => url("/t/{$this->tenant->slug}/payment/success?session_id={CHECKOUT_SESSION_ID}"),
            'cancel_url' => url("/t/{$this->tenant->slug}/payment/cancel"),
            'metadata' => [
                'booking_id' => $booking->id,
                'tenant_id' => $this->tenant->id,
            ],
        ]);

        return [
            'gateway_url' => $session->url,
            'session_id' => $session->id,
        ];
    }

    public function verifyCallback(Request $request): bool
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $endpointSecret = $this->tenant->settings['stripe_webhook_secret'] ?? env('STRIPE_WEBHOOK_SECRET');

        try {
            Webhook::constructEvent($payload, $sigHeader, $endpointSecret);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function processPayment(Request $request): array
    {
        $event = json_decode($request->getContent(), true);
        
        // 2. The Blind Webhook Vulnerability Patch: Explicitly check the event type
        if (($event['type'] ?? '') !== 'checkout.session.completed') {
            throw new \InvalidArgumentException('Ignored webhook event type.');
        }

        $session = $event['data']['object'];

        return [
            'transaction_id' => $session['payment_intent'] ?? $session['id'],
            'amount' => $session['amount_total'] / 100, // Convert back from cents
            'method' => 'Stripe',
            'booking_id' => $session['metadata']['booking_id'],
            'tenant_id' => $session['metadata']['tenant_id'],
        ];
    }

    public function refundPayment(Payment $payment): bool
    {
        // Refund logic using Stripe API
        return true;
    }
}
