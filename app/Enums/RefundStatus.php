<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum RefundStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * `succeeded → failed` es legítimo: en Stripe un reembolso puede fallar
     * días después (fase-2-diseno-pagos.md §5).
     *
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Pending => [self::Succeeded, self::Failed, self::Cancelled],
            self::Succeeded => [self::Failed],
            self::Failed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    /**
     * Estados que apartan saldo del pago: el pendiente puede completarse en el
     * proveedor en cualquier momento. Lo replica el trigger
     * `refunds_within_payment_amount`.
     */
    public function reservesAmount(): bool
    {
        return $this === self::Pending || $this === self::Succeeded;
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'En proceso',
            self::Succeeded => 'Reembolsado',
            self::Failed => 'Fallido',
            self::Cancelled => 'Cancelado',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Succeeded => 'success',
            self::Failed => 'danger',
            self::Cancelled => 'gray',
        };
    }
}
