<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Quién originó un intento: el donante en la página, el proveedor (sus
 * reintentos automáticos) o el CRM (reservado para una política propia).
 */
enum AttemptInitiator: string implements HasLabel
{
    case Donor = 'donor';
    case Provider = 'provider';
    case Crm = 'crm';

    public function getLabel(): string
    {
        return match ($this) {
            self::Donor => 'Donante',
            self::Provider => 'Proveedor (reintento automático)',
            self::Crm => 'CRM',
        };
    }
}
