<?php

declare(strict_types=1);

namespace App\Payments\Data;

use App\Enums\SubscriptionInterval;

/**
 * Datos para iniciar un donativo mensual. `cardToken` lo usa Mercado Pago
 * (`card_token_id` de `/preapproval`); el CRM nunca recibe la tarjeta.
 */
final readonly class SubscriptionRequest
{
    public function __construct(
        public int $subscriptionId,
        public string $idempotencyKey,
        public string $amount,
        public string $currency,
        public SubscriptionInterval $interval,
        public string $description,
        public ?string $donorEmail,
        public string $returnUrl,
        public ?string $cardToken = null,
    ) {}
}
