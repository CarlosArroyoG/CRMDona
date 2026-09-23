<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Quién decide el siguiente intento de cobro. Nunca reintentan los dos: el
 * CRM solo reintentaría si el dueño es `crm`. En la Fase 2 siempre es
 * `provider` (Stripe Smart Retries y Mercado Pago `/preapproval`).
 */
enum RetryOwner: string implements HasLabel
{
    case Provider = 'provider';
    case Crm = 'crm';

    public function getLabel(): string
    {
        return match ($this) {
            self::Provider => 'Proveedor',
            self::Crm => 'CRM',
        };
    }
}
