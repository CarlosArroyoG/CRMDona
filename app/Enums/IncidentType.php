<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Tipos de incidencia (fase-2-diseno-pagos.md §13). Los operativos los ve y
 * gestiona también el Coordinador; los técnicos, solo Administrador y
 * Contador.
 */
enum IncidentType: string implements HasLabel
{
    case OneTimePaymentFailed = 'one_time_payment_failed';
    case RecurringAttemptFailed = 'recurring_attempt_failed';
    case RecurringPaymentFailed = 'recurring_payment_failed';
    case SubscriptionCancelledByProvider = 'subscription_cancelled_by_provider';
    case DisputeOpened = 'dispute_opened';
    case RefundFailed = 'refund_failed';
    case SucceededWithoutDonation = 'succeeded_without_donation';
    case StateInconsistency = 'state_inconsistency';
    case ProviderUnavailable = 'provider_unavailable';
    case WebhookUnprocessable = 'webhook_unprocessable';

    public function severity(): IncidentSeverity
    {
        return match ($this) {
            self::OneTimePaymentFailed, self::RecurringAttemptFailed, self::StateInconsistency => IncidentSeverity::Warning,
            default => IncidentSeverity::Critical,
        };
    }

    public function isOperational(): bool
    {
        return match ($this) {
            self::OneTimePaymentFailed, self::RecurringAttemptFailed, self::RecurringPaymentFailed,
            self::SubscriptionCancelledByProvider, self::DisputeOpened => true,
            default => false,
        };
    }

    /**
     * @return list<self>
     */
    public static function operational(): array
    {
        return array_values(array_filter(self::cases(), fn (self $type): bool => $type->isOperational()));
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::OneTimePaymentFailed => 'Pago único fallido',
            self::RecurringAttemptFailed => 'Cobro mensual rechazado',
            self::RecurringPaymentFailed => 'Mensualidad no cobrada',
            self::SubscriptionCancelledByProvider => 'Suscripción cancelada por el proveedor',
            self::DisputeOpened => 'Contracargo o disputa',
            self::RefundFailed => 'Reembolso fallido',
            self::SucceededWithoutDonation => 'Pago exitoso sin donativo',
            self::StateInconsistency => 'Estado inconsistente',
            self::ProviderUnavailable => 'Proveedor no disponible',
            self::WebhookUnprocessable => 'Notificación no procesable',
        };
    }

    /**
     * Qué debe hacer quien la atiende, en lenguaje no técnico.
     */
    public function requiredAction(): string
    {
        return match ($this) {
            self::OneTimePaymentFailed => 'Revisar el pago y, si procede, contactar al donante.',
            self::RecurringAttemptFailed => 'El proveedor reintentará el cobro. Considera contactar al donante para que actualice su tarjeta.',
            self::RecurringPaymentFailed => 'La mensualidad no se cobró. Contacta al donante.',
            self::SubscriptionCancelledByProvider => 'El donativo mensual se canceló en el proveedor. Contacta al donante si procede.',
            self::DisputeOpened => 'Existe un contracargo que requiere revisión.',
            self::RefundFailed => 'Revisar el reembolso en el proveedor.',
            self::SucceededWithoutDonation => 'Revisar por qué el pago exitoso no generó su donativo.',
            self::StateInconsistency => 'Revisar el estado del pago en el proveedor.',
            self::ProviderUnavailable => 'El proveedor no responde. Verificar su estado de servicio.',
            self::WebhookUnprocessable => 'Revisar la notificación en la bandeja de webhooks.',
        };
    }
}
