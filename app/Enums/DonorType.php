<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum DonorType: string implements HasLabel
{
    case Individual = 'individual';
    case Organization = 'organization';

    public function getLabel(): string
    {
        return match ($this) {
            self::Individual => 'Persona física',
            self::Organization => 'Persona moral',
        };
    }

    /** Longitud del RFC según el tipo de persona. */
    public function rfcLength(): int
    {
        return match ($this) {
            self::Individual => 13,
            self::Organization => 12,
        };
    }
}
