<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Ciclo de un CFDI de donativo. `failed` es un error temporal del PAC (se
 * reintenta); `rejected` es un rechazo por datos (hay que corregir y
 * reintentar, o descartar: `discarded`, nunca timbrado). La cancelación
 * puede requerir la aceptación del receptor [V SAT]: mientras tanto queda
 * `cancellation_pending`.
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

    public function canRetry(): bool
    {
        return $this === self::Failed || $this === self::Rejected;
    }

    public function isStamped(): bool
    {
        return in_array($this, [self::Stamped, self::CancellationPending, self::Cancelled], true);
    }

    /**
     * Cuenta como el CFDI del donativo (no cancelado ni descartado).
     */
    public function isActive(): bool
    {
        return $this !== self::Cancelled && $this !== self::Discarded;
    }

    /**
     * @return list<string>
     */
    public static function inactiveValues(): array
    {
        return [self::Cancelled->value, self::Discarded->value];
    }

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
