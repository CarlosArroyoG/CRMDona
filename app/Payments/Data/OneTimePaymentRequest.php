<?php

declare(strict_types=1);

namespace App\Payments\Data;

/**
 * Datos para iniciar un pago único. `cardToken` y `paymentMethodId` solo los
 * usan proveedores que tokenizan la tarjeta en el navegador antes de cobrar
 * (Mercado Pago); el CRM nunca recibe el número de tarjeta.
 */
final readonly class OneTimePaymentRequest
{
    public function __construct(
        public int $paymentId,
        public string $idempotencyKey,
        public string $amount,
        public string $currency,
        public string $description,
        public ?string $donorEmail,
        public string $returnUrl,
        public ?string $cardToken = null,
        public ?string $paymentMethodId = null,
    ) {}
}
