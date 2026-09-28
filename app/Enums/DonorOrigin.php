<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Quién registró al donante: una persona del equipo (a mano o con una carga
 * CSV) o el propio donante en la página pública (sin usuario del CRM).
 */
enum DonorOrigin: string implements HasLabel
{
    case Manual = 'manual';
    case PublicPage = 'public_page';
    case CsvImport = 'csv_import';

    public function getLabel(): string
    {
        return match ($this) {
            self::Manual => 'Registro manual',
            self::PublicPage => 'Página pública de donativos',
            self::CsvImport => 'Carga masiva (CSV)',
        };
    }
}
