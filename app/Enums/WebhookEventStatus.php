<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum WebhookEventStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Processed = 'processed';
    case Ignored = 'ignored';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Por procesar',
            self::Processed => 'Procesado',
            self::Ignored => 'Ignorado (sin efecto)',
            self::Failed => 'Fallido',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Processed => 'success',
            self::Ignored => 'gray',
            self::Failed => 'danger',
        };
    }
}
