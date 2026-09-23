<?php

declare(strict_types=1);

namespace App\Payments\Data;

use Carbon\CarbonImmutable;

/**
 * Notificación ya autenticada. `payload` contiene solo los campos de la
 * lista permitida del proveedor. `resourceType` es propio de cada adaptador
 * (por ejemplo, `payment_intent` o `preapproval`); nulo si el evento no
 * afecta al CRM.
 */
final readonly class InboundWebhook
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $externalEventId,
        public string $eventType,
        public ?string $resourceType,
        public ?string $resourceExternalId,
        public array $payload,
        public ?CarbonImmutable $providerCreatedAt = null,
    ) {}
}
