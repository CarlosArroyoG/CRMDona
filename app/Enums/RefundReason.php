<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Motivo interno del reembolso (obligatorio). No es el código del
 * proveedor: cada adaptador lo traduce solo cuando existe equivalencia.
 * `ProviderInitiated` no se elige en el CRM: marca los reembolsos hechos
 * directamente en el panel del proveedor.
 */
enum RefundReason: string implements HasLabel
{
    case DonorRequest = 'donor_request';
    case DuplicateCharge = 'duplicate_charge';
    case IncorrectAmount = 'incorrect_amount';
    case AdministrativeError = 'administrative_error';
    case Other = 'other';
    case ProviderInitiated = 'provider_initiated';

    /**
     * Motivos que una persona puede elegir al solicitar un reembolso.
     *
     * @return array<string, string>
     */
    public static function selectable(): array
    {
        $options = [];
        foreach ([self::DonorRequest, self::DuplicateCharge, self::IncorrectAmount, self::AdministrativeError, self::Other] as $reason) {
            $options[$reason->value] = $reason->getLabel();
        }

        return $options;
    }

    public function requiresComment(): bool
    {
        return $this === self::Other;
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::DonorRequest => 'Solicitud del donante',
            self::DuplicateCharge => 'Cobro duplicado',
            self::IncorrectAmount => 'Importe incorrecto',
            self::AdministrativeError => 'Error administrativo',
            self::Other => 'Otro',
            self::ProviderInitiated => 'Hecho en el panel del proveedor',
        };
    }
}
