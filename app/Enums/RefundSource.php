<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum RefundSource: string implements HasLabel
{
    case Crm = 'crm';
    case Provider = 'provider';

    public function getLabel(): string
    {
        return match ($this) {
            self::Crm => 'Solicitado en el CRM',
            self::Provider => 'Registrado por el proveedor',
        };
    }
}
