<?php

declare(strict_types=1);

namespace App\Payments\Data;

use App\Enums\RefundStatus;
use Carbon\CarbonImmutable;

/**
 * Estado actual de un reembolso según el proveedor. Se asocia al Refund del
 * CRM por `externalId`, por la referencia propia (`crmRefundId`, metadatos)
 * o, si el proveedor no admite metadatos, al reembolso pendiente del CRM sin
 * identificador externo con el mismo importe.
 */
final readonly class RefundSnapshot
{
    public function __construct(
        public string $externalId,
        public RefundStatus $status,
        public string $amount,
        public ?string $paymentExternalId = null,
        public ?int $crmPaymentId = null,
        public ?int $crmRefundId = null,
        public ?string $failureReason = null,
        public ?CarbonImmutable $providerCreatedAt = null,
        public ?CarbonImmutable $providerUpdatedAt = null,
    ) {}
}
