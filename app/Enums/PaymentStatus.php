<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Estado del procesamiento del cobro. Reembolsos y disputas son otras
 * dimensiones (RefundState, PaymentDispute): un pago reembolsado sigue
 * `succeeded`.
 */
enum PaymentStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * Transiciones permitidas. `failed → succeeded` es legítimo: el
     * proveedor puede recuperar el cobro después (fase-2-diseno-pagos.md §5).
     *
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Pending => [self::Processing, self::Succeeded, self::Failed, self::Cancelled],
            // Stripe regresa el PaymentIntent a "requiere método de pago" si el procesamiento falla.
            self::Processing => [self::Pending, self::Succeeded, self::Failed, self::Cancelled],
            self::Failed => [self::Succeeded, self::Cancelled],
            self::Succeeded, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Processing => 'En proceso',
            self::Succeeded => 'Exitoso',
            self::Failed => 'Fallido',
            self::Cancelled => 'Cancelado',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending, self::Processing => 'warning',
            self::Succeeded => 'success',
            self::Failed => 'danger',
            self::Cancelled => 'gray',
        };
    }
}
