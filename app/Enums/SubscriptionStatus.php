<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Un cobro fallido nunca cancela la suscripción: la cancelan el estado real
 * del proveedor o una persona autorizada.
 */
enum SubscriptionStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Active = 'active';
    case PastDue = 'past_due';
    case Paused = 'paused';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    /**
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Pending => [self::Active, self::PastDue, self::Paused, self::Cancelled, self::Expired],
            self::Active => [self::PastDue, self::Paused, self::Cancelled],
            self::PastDue => [self::Active, self::Paused, self::Cancelled],
            self::Paused => [self::Active, self::PastDue, self::Cancelled],
            self::Cancelled, self::Expired => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    public function isFinal(): bool
    {
        return $this->allowedNext() === [];
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Por activar',
            self::Active => 'Activa',
            self::PastDue => 'Con cobro pendiente',
            self::Paused => 'Pausada',
            self::Cancelled => 'Cancelada',
            self::Expired => 'No se activó',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending, self::Paused => 'gray',
            self::Active => 'success',
            self::PastDue => 'warning',
            self::Cancelled, self::Expired => 'danger',
        };
    }
}
