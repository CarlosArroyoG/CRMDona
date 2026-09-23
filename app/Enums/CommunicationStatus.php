<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Estado de un envío. `sent` = el servidor de correo lo aceptó (no garantiza
 * la entrega). `bounced` requiere integrar los avisos de rebote del proveedor
 * de correo, que aún no se elige (pendiente externo).
 */
enum CommunicationStatus: string implements HasColor, HasLabel
{
    case Queued = 'queued';
    case Sending = 'sending';
    case Sent = 'sent';
    case Failed = 'failed';
    case Bounced = 'bounced';
    case Skipped = 'skipped';

    public function getLabel(): string
    {
        return match ($this) {
            self::Queued => 'En cola',
            self::Sending => 'Enviando',
            self::Sent => 'Enviado',
            self::Failed => 'Fallido',
            self::Bounced => 'Rebotado',
            self::Skipped => 'No enviado',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Queued, self::Sending => 'gray',
            self::Sent => 'success',
            self::Failed, self::Bounced => 'danger',
            self::Skipped => 'warning',
        };
    }
}
