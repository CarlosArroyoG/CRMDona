<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Estado del envío del aviso a Contabilidad de un donativo confirmado. Es
 * independiente del procesamiento contable (que marca una persona).
 */
enum AccountingNoticeStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Sending = 'sending';
    case Sent = 'sent';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function canRetry(): bool
    {
        return $this === self::Failed || $this === self::Skipped;
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'En cola',
            self::Sending => 'Enviando',
            self::Sent => 'Enviado',
            self::Failed => 'Fallido',
            self::Skipped => 'No enviado',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending, self::Sending => 'gray',
            self::Sent => 'success',
            self::Failed => 'danger',
            self::Skipped => 'warning',
        };
    }
}
