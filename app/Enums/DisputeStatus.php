<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Estados normalizados de disputas y contracargos de ambos proveedores.
 */
enum DisputeStatus: string implements HasColor, HasLabel
{
    case Open = 'open';
    case UnderReview = 'under_review';
    case Won = 'won';
    case Lost = 'lost';
    case Closed = 'closed';

    /**
     * `lost → won` es legítimo: Stripe puede revertir una pérdida tarde.
     *
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Open => [self::UnderReview, self::Won, self::Lost, self::Closed],
            self::UnderReview => [self::Open, self::Won, self::Lost, self::Closed],
            self::Lost => [self::Won],
            self::Won, self::Closed => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    public function isOpen(): bool
    {
        return $this === self::Open || $this === self::UnderReview;
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Open => 'Abierta',
            self::UnderReview => 'En revisión del proveedor',
            self::Won => 'Ganada',
            self::Lost => 'Perdida',
            self::Closed => 'Cerrada',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Open => 'danger',
            self::UnderReview => 'warning',
            self::Won => 'success',
            self::Lost => 'danger',
            self::Closed => 'gray',
        };
    }
}
