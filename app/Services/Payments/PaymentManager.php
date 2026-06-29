<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGatewayInterface;
use App\Models\Tenant;
use App\Services\Payments\Gateways\StripeGateway;
use InvalidArgumentException;

class PaymentManager
{
    public static function resolve(string $gatewayName, Tenant $tenant): PaymentGatewayInterface
    {
        return match (strtolower($gatewayName)) {
            'stripe' => new StripeGateway($tenant),
            // Add other gateways here as we build them
            default => throw new InvalidArgumentException("Unsupported gateway: {$gatewayName}"),
        };
    }
}
