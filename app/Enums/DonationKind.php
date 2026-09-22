<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum DonationKind: string implements HasLabel
{
    case Monetary = 'monetary';
    case InKind = 'in_kind';

    public function getLabel(): string
    {
        return match ($this) {
            self::Monetary => 'Dinero',
            self::InKind => 'Especie',
        };
    }
}
