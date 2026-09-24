<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Histórico: estados de los CFDI que el CRM registró cuando todavía los
 * emitía. Solo se usa para leer `cfdis` (docs/tecnico/cfdi-externo.md).
 */
enum CfdiStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Stamping = 'stamping';
    case Stamped = 'stamped';
    case Failed = 'failed';
    case Rejected = 'rejected';
    case Discarded = 'discarded';
    case CancellationPending = 'cancellation_pending';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'En cola',
            self::Stamping => 'Timbrando',
            self::Stamped => 'Timbrado',
            self::Failed => 'Error temporal',
            self::Rejected => 'Rechazado por datos',
            self::Discarded => 'Descartado',
            self::CancellationPending => 'Cancelación en proceso',
            self::Cancelled => 'Cancelado',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending, self::Stamping, self::Discarded => 'gray',
            self::Stamped => 'success',
            self::Failed, self::CancellationPending => 'warning',
            self::Rejected, self::Cancelled => 'danger',
        };
    }
}
