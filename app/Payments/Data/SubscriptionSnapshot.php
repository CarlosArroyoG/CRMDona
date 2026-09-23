<?php

declare(strict_types=1);

namespace App\Payments\Data;

use App\Enums\SubscriptionStatus;
use Carbon\CarbonImmutable;

/**
 * Estado actual de una suscripción según el proveedor, ya normalizado.
 */
final readonly class SubscriptionSnapshot
{
    public function __construct(
        public SubscriptionStatus $status,
        public string $providerStatus,
        public ?string $externalId = null,
        public ?int $crmSubscriptionId = null,
        public ?string $amount = null,
        public ?CarbonImmutable $providerUpdatedAt = null,
        public ?CarbonImmutable $nextChargeAt = null,
        public ?CarbonImmutable $startedAt = null,
        public ?CarbonImmutable $cancelledAt = null,
    ) {}
}
