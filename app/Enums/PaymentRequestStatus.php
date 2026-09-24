<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Situación de una solicitud de pago. En la base solo existen `open`, `paid`
 * y `cancelled`; "Vencida" se calcula (abierta con `expires_at` pasado) para
 * no depender de un proceso programado.
 */
enum PaymentRequestStatus: string implements HasColor, HasLabel
{
    case Open = 'open';
    case Paid = 'paid';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    /**
     * Valores que se guardan en `payment_requests.status`.
     *
     * @return list<self>
     */
    public static function stored(): array
    {
        return [self::Open, self::Paid, self::Cancelled];
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Open => 'Esperando pago',
            self::Paid => 'Pagada',
            self::Cancelled => 'Cancelada',
            self::Expired => 'Vencida',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Open => 'info',
            self::Paid => 'success',
            self::Cancelled => 'gray',
            self::Expired => 'warning',
        };
    }
}
