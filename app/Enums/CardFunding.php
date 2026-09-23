<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Tipo de fondeo de la tarjeta según el proveedor de pagos. Define la forma de
 * pago del CFDI: [V] catálogo c_FormaPago del SAT, 04 "Tarjeta de crédito" y
 * 28 "Tarjeta de débito". Prepago y desconocido quedan bloqueados [F].
 */
enum CardFunding: string implements HasLabel
{
    case Credit = 'credit';
    case Debit = 'debit';
    case Prepaid = 'prepaid';
    case Unknown = 'unknown';

    public function cfdiPaymentForm(): ?string
    {
        return match ($this) {
            self::Credit => '04',
            self::Debit => '28',
            self::Prepaid, self::Unknown => null,
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Credit => 'Crédito',
            self::Debit => 'Débito',
            self::Prepaid => 'Prepago',
            self::Unknown => 'Desconocido',
        };
    }
}
