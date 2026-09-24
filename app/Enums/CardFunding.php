<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Tipo de fondeo de la tarjeta según el proveedor de pagos. Se informa a
 * Contabilidad en el aviso de cada donativo en línea; el CRM no lo traduce a
 * claves del SAT (los CFDI se emiten fuera, docs/tecnico/cfdi-externo.md).
 */
enum CardFunding: string implements HasLabel
{
    case Credit = 'credit';
    case Debit = 'debit';
    case Prepaid = 'prepaid';
    case Unknown = 'unknown';

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
