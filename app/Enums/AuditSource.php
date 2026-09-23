<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Procedencia de un cambio en la bitácora. Dice qué clase de actor lo
 * produjo; el detalle técnico vive en webhook_events y en las tablas de
 * pagos (fase-2-diseno-pagos.md §19).
 */
enum AuditSource: string implements HasLabel
{
    case User = 'user';
    case Webhook = 'webhook';
    case Job = 'job';
    case Synchronization = 'synchronization';
    case Console = 'console';
    case Donor = 'donor';

    public function getLabel(): string
    {
        return match ($this) {
            self::User => 'Usuario',
            self::Webhook => 'Notificación del proveedor',
            self::Job => 'Proceso automático',
            self::Synchronization => 'Sincronización con el proveedor',
            self::Console => 'Consola del servidor',
            self::Donor => 'Donante (página pública o enlace de baja)',
        };
    }
}
