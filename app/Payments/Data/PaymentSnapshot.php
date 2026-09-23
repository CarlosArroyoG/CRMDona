<?php

declare(strict_types=1);

namespace App\Payments\Data;

use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Enums\RetryOwner;
use Carbon\CarbonImmutable;

/**
 * Estado actual de un cobro según el proveedor, ya normalizado. Se localiza
 * el Payment del CRM por `externalId` o por la referencia propia
 * (`crmPaymentId`, que viaja en los metadatos del proveedor). Una mensualidad
 * nueva se crea a partir de su suscripción y periodo.
 */
final readonly class PaymentSnapshot
{
    /**
     * @param  list<AttemptSnapshot>  $attempts
     * @param  list<RefundSnapshot>  $refunds
     */
    public function __construct(
        public PaymentKind $kind,
        public PaymentStatus $status,
        public string $providerStatus,
        public ?string $externalId = null,
        public ?int $crmPaymentId = null,
        public ?string $amount = null,
        public ?CarbonImmutable $providerUpdatedAt = null,
        public ?CarbonImmutable $succeededAt = null,
        public ?string $subscriptionExternalId = null,
        public ?int $crmSubscriptionId = null,
        public ?CarbonImmutable $billingPeriodStart = null,
        public array $attempts = [],
        public array $refunds = [],
        public ?RetryOwner $nextRetryOwner = null,
        public ?CarbonImmutable $nextRetryAt = null,
    ) {}
}
