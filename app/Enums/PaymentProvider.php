<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Proveedor de pagos. Cada Payment y Subscription guarda el suyo para
 * siempre. `fake` solo existe en local y testing (FakeGateway).
 */
enum PaymentProvider: string implements HasLabel
{
    case Stripe = 'stripe';
    case MercadoPago = 'mercado_pago';
    case Fake = 'fake';

    public function getLabel(): string
    {
        return match ($this) {
            self::Stripe => 'Stripe',
            self::MercadoPago => 'Mercado Pago',
            self::Fake => 'Simulado (pruebas)',
        };
    }
}
