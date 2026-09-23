<?php

declare(strict_types=1);

namespace App\Payments\Data;

use App\Enums\DisputeStatus;
use Carbon\CarbonImmutable;

/**
 * Disputa o contracargo según el proveedor. El pago se localiza por su
 * identificador externo o por el de uno de sus intentos (Stripe reporta el
 * cargo; Mercado Pago, el payment).
 */
final readonly class DisputeSnapshot
{
    public function __construct(
        public string $externalId,
        public DisputeStatus $status,
        public string $providerStatus,
        public ?string $paymentExternalId = null,
        public ?string $attemptExternalId = null,
        public ?string $amount = null,
        public ?string $providerReason = null,
        public ?CarbonImmutable $openedAt = null,
        public ?CarbonImmutable $evidenceDueAt = null,
        public ?CarbonImmutable $closedAt = null,
        public ?CarbonImmutable $providerUpdatedAt = null,
    ) {}
}
