<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Categoría interna de un rechazo, independiente del proveedor. El código
 * original del proveedor se guarda aparte (payment_attempts.provider_code).
 */
enum FailureCategory: string implements HasLabel
{
    case ExpiredCard = 'expired_card';
    case InsufficientFunds = 'insufficient_funds';
    case InvalidPaymentData = 'invalid_payment_data';
    case AuthenticationFailed = 'authentication_failed';
    case Declined = 'declined';
    case SuspectedFraud = 'suspected_fraud';
    case Duplicate = 'duplicate';
    case TooManyAttempts = 'too_many_attempts';
    case ProcessingError = 'processing_error';
    case ProviderUnavailable = 'provider_unavailable';
    case Unknown = 'unknown';

    /**
     * Rechazos que el propio donante corrige (otra tarjeta, datos bien
     * escritos, fondos). Un pago único que termina fallido por uno de estos
     * no abre incidencia: no hay nada que revisar en la organización.
     */
    public function isDonorCorrectable(): bool
    {
        return match ($this) {
            self::ExpiredCard, self::InsufficientFunds, self::InvalidPaymentData, self::AuthenticationFailed,
            self::Declined, self::TooManyAttempts => true,
            default => false,
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::ExpiredCard => 'Tarjeta vencida',
            self::InsufficientFunds => 'Fondos insuficientes',
            self::InvalidPaymentData => 'Datos de pago incorrectos',
            self::AuthenticationFailed => 'Autenticación fallida',
            self::Declined => 'Rechazada por el banco',
            self::SuspectedFraud => 'Sospecha de fraude',
            self::Duplicate => 'Pago duplicado',
            self::TooManyAttempts => 'Demasiados intentos',
            self::ProcessingError => 'Error de procesamiento',
            self::ProviderUnavailable => 'Proveedor no disponible',
            self::Unknown => 'Motivo no identificado',
        };
    }

    /**
     * Mensaje seguro para el donante: nunca revela el motivo exacto de
     * fraude ni datos técnicos.
     */
    public function donorMessage(): string
    {
        return match ($this) {
            self::ExpiredCard => 'Tu tarjeta está vencida. Intenta con otra tarjeta.',
            self::InsufficientFunds => 'La tarjeta no tiene fondos suficientes. Intenta con otra tarjeta.',
            self::InvalidPaymentData => 'Revisa los datos de la tarjeta e intenta de nuevo.',
            self::AuthenticationFailed => 'No se pudo verificar la tarjeta con tu banco. Intenta de nuevo.',
            self::TooManyAttempts => 'Hubo demasiados intentos. Espera un momento e intenta de nuevo.',
            self::ProviderUnavailable, self::ProcessingError => 'No pudimos procesar el pago en este momento. Intenta más tarde.',
            default => 'Tu banco rechazó el pago. Intenta con otra tarjeta o comunícate con tu banco.',
        };
    }
}
