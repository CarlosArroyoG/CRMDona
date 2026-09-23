<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Severidad fija por tipo de incidencia; no hay escalamiento automático.
 */
enum IncidentSeverity: string implements HasColor, HasLabel
{
    case Warning = 'warning';
    case Critical = 'critical';

    public function getLabel(): string
    {
        return match ($this) {
            self::Warning => 'Advertencia',
            self::Critical => 'Crítica',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Warning => 'warning',
            self::Critical => 'danger',
        };
    }
}
