<?php

declare(strict_types=1);

namespace App\Payments\Data;

/**
 * Solicitud de reembolso al proveedor. `providerReason` ya viene traducido
 * por el adaptador (nunca texto libre del CRM). La misma `idempotencyKey`
 * se reutiliza en cada reintento.
 */
final readonly class RefundRequest
{
    public function __construct(
        public int $refundId,
        public int $paymentId,
        public string $idempotencyKey,
        public string $amount,
        public ?string $paymentExternalId,
        public ?string $attemptExternalId,
        public ?string $providerReason,
    ) {}
}
