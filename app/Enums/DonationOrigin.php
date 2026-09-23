<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Origen del donativo. `manual`: lo registra una persona. `online`: nace de
 * un pago en línea exitoso (payment_id), sin actor humano ni usuario
 * "sistema".
 */
enum DonationOrigin: string implements HasLabel
{
    case Manual = 'manual';
    case Online = 'online';

    public function getLabel(): string
    {
        return match ($this) {
            self::Manual => 'Registro manual',
            self::Online => 'Pago en línea',
        };
    }
}
