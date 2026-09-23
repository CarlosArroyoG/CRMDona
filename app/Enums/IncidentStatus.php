<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Seguimiento de incidencias (RF-01). Leer la notificación no cambia el
 * estado: solo una persona la toma o la resuelve.
 */
enum IncidentStatus: string implements HasColor, HasLabel
{
    case New = 'new';
    case Reviewing = 'reviewing';
    case Resolved = 'resolved';

    public function getLabel(): string
    {
        return match ($this) {
            self::New => 'Nueva',
            self::Reviewing => 'En revisión',
            self::Resolved => 'Resuelta',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::New => 'danger',
            self::Reviewing => 'warning',
            self::Resolved => 'success',
        };
    }
}
