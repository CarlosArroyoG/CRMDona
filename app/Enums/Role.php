<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Rol único de cada usuario (ADR-002). El valor en inglés es lo que se guarda
 * en `users.role`; la etiqueta en español es lo que ve el usuario.
 */
enum Role: string implements HasLabel
{
    case Administrator = 'administrator';
    case FundraisingCoordinator = 'fundraising_coordinator';
    case Accountant = 'accountant';
    case ReadOnly = 'read_only';

    public function getLabel(): string
    {
        return match ($this) {
            self::Administrator => 'Administrador',
            self::FundraisingCoordinator => 'Coordinador de procuración de fondos',
            self::Accountant => 'Contador',
            self::ReadOnly => 'Solo lectura',
        };
    }

    /**
     * Por ahora solo el Administrador entra al panel. Los demás roles se
     * habilitan en la fase que construya los módulos que usarán.
     */
    public function canAccessPanel(): bool
    {
        return $this === self::Administrator;
    }
}
