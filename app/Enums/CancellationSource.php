<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum CancellationSource: string implements HasLabel
{
    case CrmUser = 'crm_user';
    case Provider = 'provider';
    case Donor = 'donor';

    public function getLabel(): string
    {
        return match ($this) {
            self::CrmUser => 'Usuario del CRM',
            self::Provider => 'Proveedor de pagos',
            self::Donor => 'Donante',
        };
    }
}
