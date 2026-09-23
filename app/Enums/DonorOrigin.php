<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Quién registró al donante: una persona del equipo (manual) o el propio
 * donante en la página pública (sin usuario del CRM).
 */
enum DonorOrigin: string implements HasLabel
{
    case Manual = 'manual';
    case PublicPage = 'public_page';

    public function getLabel(): string
    {
        return match ($this) {
            self::Manual => 'Registro manual',
            self::PublicPage => 'Página pública de donativos',
        };
    }
}
