<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Money;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Situación de reembolso de un pago. Se calcula con la suma de reembolsos
 * exitosos; no se guarda (fase-2-diseno-pagos.md §9).
 */
enum RefundState: string implements HasColor, HasLabel
{
    case None = 'none';
    case Partial = 'partial';
    case Full = 'full';

    public static function fromAmounts(string $paymentAmount, ?string $refunded): self
    {
        $refunded = $refunded !== null && is_numeric($refunded) ? $refunded : '0';
        $paymentAmount = is_numeric($paymentAmount) ? $paymentAmount : '0';

        return match (true) {
            bccomp($refunded, '0', 2) <= 0 => self::None,
            bccomp($refunded, $paymentAmount, 2) >= 0 => self::Full,
            default => self::Partial,
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::None => 'Sin reembolso',
            self::Partial => 'Parcialmente reembolsado',
            self::Full => 'Totalmente reembolsado',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::None => 'gray',
            self::Partial => 'warning',
            self::Full => 'danger',
        };
    }

    /**
     * Texto para mostrar junto al importe reembolsado.
     */
    public static function describe(string $paymentAmount, ?string $refunded): string
    {
        $state = self::fromAmounts($paymentAmount, $refunded);

        return $state === self::None ? $state->getLabel() : $state->getLabel().' ('.Money::format($refunded).')';
    }
}
