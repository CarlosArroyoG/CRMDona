<?php

declare(strict_types=1);

namespace App\Payments\Data;

/**
 * Respuesta al iniciar un pago o una suscripción. Según el proveedor trae
 * lo necesario para que la página pública muestre el formulario oficial
 * (`clientSecret`, `redirectUrl`) o el resultado inmediato (`snapshot`).
 * Nunca contiene datos de tarjeta.
 */
final readonly class CheckoutResult
{
    public function __construct(
        public ?string $paymentExternalId = null,
        public ?string $subscriptionExternalId = null,
        public ?string $clientSecret = null,
        public ?string $redirectUrl = null,
        public ?ProviderSnapshot $snapshot = null,
    ) {}
}
