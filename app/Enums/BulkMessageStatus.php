<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Ciclo de un envío masivo: se redacta (borrador), se envía (preparando: la
 * cola registra un correo por destinatario) y queda enviado. Se puede detener
 * mientras queden correos en cola: los pendientes quedan "No enviado".
 */
enum BulkMessageStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Preparing = 'preparing';
    case Sent = 'sent';
    case Stopped = 'stopped';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Borrador',
            self::Preparing => 'Preparando',
            self::Sent => 'Enviado',
            self::Stopped => 'Detenido',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Preparing => 'info',
            self::Sent => 'success',
            self::Stopped => 'danger',
        };
    }
}
