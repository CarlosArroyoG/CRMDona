<?php

declare(strict_types=1);

namespace App\Payments\Gateways\Stripe;

use App\Enums\AttemptInitiator;
use App\Enums\DisputeStatus;
use App\Enums\FailureCategory;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Enums\RetryOwner;
use App\Enums\SubscriptionStatus;
use App\Payments\Data\AttemptSnapshot;
use App\Payments\Data\DisputeSnapshot;
use App\Payments\Data\PaymentSnapshot;
use App\Payments\Data\RefundSnapshot;
use App\Payments\Data\SubscriptionSnapshot;
use App\Support\SensitiveData;
use Carbon\CarbonImmutable;

/**
 * Traduce objetos de Stripe (API 2026-08-26.dahlia, como arreglos) al
 * modelo interno. Sin llamadas de red: se prueba con ejemplos fijos.
 *
 * Fuentes: docs.stripe.com/payments/paymentintents/lifecycle,
 * /billing/subscriptions/overview, /refunds, /disputes/how-disputes-work,
 * /declines/codes y los tipos del SDK stripe/stripe-php v21.3.2.
 * [S] = por confirmar en sandbox (ver fase-2-diseno-pagos.md).
 */
final class StripeMapper
{
    /**
     * Campos del evento que se guardan en webhook_events.payload (§18.1).
     */
    public const array WEBHOOK_ALLOWLIST = [
        'id', 'type', 'created', 'livemode', 'api_version', 'request.id', 'request.idempotency_key', 'pending_webhooks',
        'data.object.id', 'data.object.object', 'data.object.status', 'data.object.amount', 'data.object.amount_received',
        'data.object.amount_refunded', 'data.object.amount_paid', 'data.object.amount_due', 'data.object.currency',
        'data.object.created', 'data.object.payment_intent', 'data.object.invoice', 'data.object.charge',
        'data.object.subscription', 'data.object.customer', 'data.object.mode', 'data.object.payment_status',
        'data.object.metadata.crm_payment_id', 'data.object.metadata.crm_subscription_id', 'data.object.metadata.crm_refund_id',
        'data.object.last_payment_error.code', 'data.object.last_payment_error.decline_code', 'data.object.failure_code',
        'data.object.outcome.type', 'data.object.outcome.reason', 'data.object.outcome.network_status',
        'data.object.payment_method_details.card.brand', 'data.object.payment_method_details.card.last4',
        'data.object.attempt_count', 'data.object.next_payment_attempt', 'data.object.billing_reason',
        'data.object.period_start', 'data.object.period_end', 'data.object.parent.subscription_details.subscription',
        'data.object.cancel_at_period_end', 'data.object.canceled_at', 'data.object.pause_collection.behavior',
        'data.object.reason', 'data.object.evidence_details.due_by', 'data.object.is_charge_refundable',
        'data.previous_attributes#keys',
    ];

    /**
     * Qué recurso consultar para un evento: [tipo, identificador] o null si
     * el evento no afecta al CRM.
     *
     * @param  array<string, mixed>  $object
     * @return array{0: string, 1: string}|null
     */
    public static function route(string $eventType, array $object): ?array
    {
        $id = self::string($object['id'] ?? null);
        $prefix = explode('.', $eventType)[0];

        return match (true) {
            $id === null => null,
            $prefix === 'checkout' => ['checkout_session', $id],
            $prefix === 'payment_intent' => ['payment_intent', $id],
            str_starts_with($eventType, 'charge.dispute.') => ['dispute', $id],
            $eventType === 'charge.refund.updated', $prefix === 'refund' => ['refund', $id],
            $prefix === 'charge' => ($pi = self::string($object['payment_intent'] ?? null)) !== null ? ['payment_intent', $pi] : null,
            $eventType === 'invoice_payment.paid' => ($invoice = self::string($object['invoice'] ?? null)) !== null ? ['invoice', $invoice] : null,
            $prefix === 'invoice' => ['invoice', $id],
            str_starts_with($eventType, 'customer.subscription.') => ['subscription', $id],
            default => null,
        };
    }

    /**
     * Pago único: PaymentIntent y sus cargos (cada cargo es un intento).
     *
     * @param  array<string, mixed>  $intent
     * @param  list<array<string, mixed>>  $charges
     * @param  list<array<string, mixed>>  $refunds
     */
    public static function oneTimePayment(array $intent, array $charges, array $refunds): PaymentSnapshot
    {
        $status = self::string($intent['status'] ?? null) ?? 'requires_payment_method';

        return new PaymentSnapshot(
            kind: PaymentKind::OneTime,
            status: match ($status) {
                'processing' => PaymentStatus::Processing,
                'succeeded' => PaymentStatus::Succeeded,
                'canceled' => $charges !== [] ? PaymentStatus::Failed : PaymentStatus::Cancelled,
                // requires_payment_method (inicial o tras un rechazo), requires_confirmation, requires_action, requires_capture
                default => PaymentStatus::Pending,
            },
            providerStatus: $status,
            externalId: self::string($intent['id'] ?? null),
            crmPaymentId: self::metadataInt($intent, 'crm_payment_id'),
            amount: self::amount($intent['amount'] ?? null),
            succeededAt: $status === 'succeeded' ? self::latestSuccess($charges) : null,
            attempts: array_map(fn (array $charge): AttemptSnapshot => self::attempt($charge, AttemptInitiator::Donor), $charges),
            refunds: array_map(self::refund(...), $refunds),
        );
    }

    /**
     * Sesión de Checkout que aún no tiene PaymentIntent (abierta o vencida).
     *
     * @param  array<string, mixed>  $session
     */
    public static function sessionWithoutIntent(array $session): PaymentSnapshot
    {
        $status = self::string($session['status'] ?? null) ?? 'open';

        return new PaymentSnapshot(
            kind: PaymentKind::OneTime,
            status: $status === 'expired' ? PaymentStatus::Cancelled : PaymentStatus::Pending,
            providerStatus: "checkout_session_{$status}",
            crmPaymentId: self::metadataInt($session, 'crm_payment_id'),
        );
    }

    /**
     * Mensualidad: la factura (Invoice) del periodo es el Payment; cada cargo
     * de sus PaymentIntents es un intento (Smart Retries crea un cargo por
     * reintento [V]).
     *
     * @param  array<string, mixed>  $invoice
     * @param  list<array<string, mixed>>  $charges
     * @param  list<array<string, mixed>>  $refunds
     */
    public static function recurringPayment(array $invoice, array $charges, array $refunds): PaymentSnapshot
    {
        $status = self::string($invoice['status'] ?? null) ?? 'draft';
        $attemptCount = is_int($invoice['attempt_count'] ?? null) ? $invoice['attempt_count'] : 0;
        $nextAttempt = self::timestamp($invoice['next_payment_attempt'] ?? null);
        $initiator = ($invoice['billing_reason'] ?? null) === 'subscription_create' ? AttemptInitiator::Donor : AttemptInitiator::Provider;

        $mapped = match (true) {
            $status === 'paid' => PaymentStatus::Succeeded,
            $status === 'void' => PaymentStatus::Cancelled,
            $status === 'uncollectible' => PaymentStatus::Failed,
            // Abierta, con intentos y sin siguiente reintento: Smart Retries se agotó
            // (con la configuración "dejar la suscripción vencida") [V]; [S] confirmar en sandbox.
            $status === 'open' && $attemptCount > 0 && $nextAttempt === null => PaymentStatus::Failed,
            default => PaymentStatus::Pending,
        };

        /** @var array<string, mixed> $parent */
        $parent = is_array($invoice['parent'] ?? null) ? $invoice['parent'] : [];
        /** @var array<string, mixed> $details */
        $details = is_array($parent['subscription_details'] ?? null) ? $parent['subscription_details'] : [];
        $subscription = $details['subscription'] ?? null;

        return new PaymentSnapshot(
            kind: PaymentKind::RecurringCharge,
            status: $mapped,
            providerStatus: $status,
            externalId: self::string($invoice['id'] ?? null),
            amount: self::amount($invoice['amount_due'] ?? null),
            succeededAt: $mapped === PaymentStatus::Succeeded ? self::paidAt($invoice) : null,
            subscriptionExternalId: is_array($subscription) ? self::string($subscription['id'] ?? null) : self::string($subscription),
            crmSubscriptionId: is_array($details['metadata'] ?? null) ? self::int($details['metadata']['crm_subscription_id'] ?? null) : null,
            billingPeriodStart: self::billingPeriodStart($invoice),
            attempts: array_map(fn (array $charge): AttemptSnapshot => self::attempt($charge, $initiator), $charges),
            refunds: array_map(self::refund(...), $refunds),
            nextRetryOwner: $mapped === PaymentStatus::Pending && $nextAttempt !== null ? RetryOwner::Provider : null,
            nextRetryAt: $mapped === PaymentStatus::Pending ? $nextAttempt : null,
        );
    }

    /**
     * @param  array<string, mixed>  $subscription
     */
    public static function subscription(array $subscription): SubscriptionSnapshot
    {
        $status = self::string($subscription['status'] ?? null) ?? 'incomplete';
        $paused = is_array($subscription['pause_collection'] ?? null);
        /** @var array<string, mixed> $items */
        $items = is_array($subscription['items'] ?? null) ? $subscription['items'] : [];
        /** @var list<array<string, mixed>> $data */
        $data = is_array($items['data'] ?? null) ? array_values($items['data']) : [];

        return new SubscriptionSnapshot(
            status: match ($status) {
                'incomplete' => SubscriptionStatus::Pending,
                'incomplete_expired' => SubscriptionStatus::Expired,
                'trialing' => SubscriptionStatus::Active,
                'active' => $paused ? SubscriptionStatus::Paused : SubscriptionStatus::Active,
                // "unpaid" no debería ocurrir con la configuración de producción (ver §16.1).
                'past_due', 'unpaid' => SubscriptionStatus::PastDue,
                'paused' => SubscriptionStatus::Paused,
                'canceled' => SubscriptionStatus::Cancelled,
                default => SubscriptionStatus::Pending,
            },
            providerStatus: $paused ? "{$status}_pause_collection" : $status,
            externalId: self::string($subscription['id'] ?? null),
            crmSubscriptionId: self::metadataInt($subscription, 'crm_subscription_id'),
            nextChargeAt: isset($data[0]) ? self::timestamp($data[0]['current_period_end'] ?? null) : null,
            startedAt: self::timestamp($subscription['start_date'] ?? null),
            cancelledAt: self::timestamp($subscription['canceled_at'] ?? null),
        );
    }

    /**
     * Sesión de Checkout de suscripción que venció sin completarse.
     *
     * @param  array<string, mixed>  $session
     */
    public static function expiredSubscriptionSession(array $session): SubscriptionSnapshot
    {
        return new SubscriptionSnapshot(
            status: SubscriptionStatus::Expired,
            providerStatus: 'checkout_session_expired',
            crmSubscriptionId: self::metadataInt($session, 'crm_subscription_id'),
        );
    }

    /**
     * @param  array<string, mixed>  $refund
     */
    public static function refund(array $refund): RefundSnapshot
    {
        $status = self::string($refund['status'] ?? null) ?? 'pending';

        return new RefundSnapshot(
            externalId: (string) self::string($refund['id'] ?? null),
            status: match ($status) {
                'succeeded' => RefundStatus::Succeeded,
                'failed' => RefundStatus::Failed,
                'canceled' => RefundStatus::Cancelled,
                default => RefundStatus::Pending,
            },
            amount: self::amount($refund['amount'] ?? null) ?? '0.00',
            paymentExternalId: self::string($refund['payment_intent'] ?? null),
            crmPaymentId: self::metadataInt($refund, 'crm_payment_id'),
            crmRefundId: self::metadataInt($refund, 'crm_refund_id'),
            failureReason: self::string($refund['failure_reason'] ?? null),
            providerCreatedAt: self::timestamp($refund['created'] ?? null),
        );
    }

    /**
     * Estados de Stripe [V]: warning_needs_response, warning_under_review,
     * warning_closed, needs_response, under_review, won, lost y prevented.
     *
     * @param  array<string, mixed>  $dispute
     */
    public static function dispute(array $dispute): DisputeSnapshot
    {
        $status = self::string($dispute['status'] ?? null) ?? 'needs_response';
        $mapped = match ($status) {
            'warning_under_review', 'under_review' => DisputeStatus::UnderReview,
            'won' => DisputeStatus::Won,
            'lost' => DisputeStatus::Lost,
            'warning_closed', 'prevented' => DisputeStatus::Closed,
            default => DisputeStatus::Open,
        };
        /** @var array<string, mixed> $evidence */
        $evidence = is_array($dispute['evidence_details'] ?? null) ? $dispute['evidence_details'] : [];

        return new DisputeSnapshot(
            externalId: (string) self::string($dispute['id'] ?? null),
            status: $mapped,
            providerStatus: $status,
            paymentExternalId: self::string($dispute['payment_intent'] ?? null),
            attemptExternalId: self::string($dispute['charge'] ?? null),
            amount: self::amount($dispute['amount'] ?? null),
            providerReason: self::string($dispute['reason'] ?? null),
            openedAt: self::timestamp($dispute['created'] ?? null),
            evidenceDueAt: self::timestamp($evidence['due_by'] ?? null),
        );
    }

    /**
     * Códigos de rechazo de Stripe → categoría interna (docs.stripe.com/declines/codes [V]).
     */
    public static function failureCategory(?string $code): FailureCategory
    {
        return match ($code) {
            'expired_card' => FailureCategory::ExpiredCard,
            'insufficient_funds' => FailureCategory::InsufficientFunds,
            'incorrect_cvc', 'invalid_cvc', 'incorrect_number', 'invalid_number', 'invalid_expiry_month',
            'invalid_expiry_year', 'incorrect_zip', 'invalid_account' => FailureCategory::InvalidPaymentData,
            'authentication_required', 'authentication_not_handled' => FailureCategory::AuthenticationFailed,
            'fraudulent', 'stolen_card', 'lost_card', 'pickup_card', 'merchant_blacklist', 'highest_risk_level' => FailureCategory::SuspectedFraud,
            'duplicate_transaction' => FailureCategory::Duplicate,
            'card_velocity_exceeded', 'withdrawal_count_limit_exceeded', 'pin_try_exceeded' => FailureCategory::TooManyAttempts,
            'processing_error', 'issuer_not_available', 'reenter_transaction', 'try_again_later' => FailureCategory::ProcessingError,
            null, '' => FailureCategory::Unknown,
            default => FailureCategory::Declined,
        };
    }

    /**
     * @return numeric-string|null
     */
    public static function amount(mixed $minorUnits): ?string
    {
        return is_int($minorUnits) ? bcdiv((string) $minorUnits, '100', 2) : null;
    }

    public static function minorUnits(string $amount): int
    {
        return (int) bcmul(is_numeric($amount) ? $amount : '0', '100', 0);
    }

    /**
     * @param  array<string, mixed>  $charge
     */
    private static function attempt(array $charge, AttemptInitiator $initiator): AttemptSnapshot
    {
        $status = self::string($charge['status'] ?? null);
        /** @var array<string, mixed> $outcome */
        $outcome = is_array($charge['outcome'] ?? null) ? $charge['outcome'] : [];
        /** @var array<string, mixed> $details */
        $details = is_array($charge['payment_method_details'] ?? null) ? $charge['payment_method_details'] : [];
        /** @var array<string, mixed> $card */
        $card = is_array($details['card'] ?? null) ? $details['card'] : [];
        $code = self::string($outcome['reason'] ?? null) ?? self::string($charge['failure_code'] ?? null);
        $failed = $status === 'failed';
        $last4 = self::string($card['last4'] ?? null);

        return new AttemptSnapshot(
            externalId: (string) self::string($charge['id'] ?? null),
            status: match ($status) {
                'succeeded' => PaymentAttemptStatus::Succeeded,
                'failed' => PaymentAttemptStatus::Failed,
                default => PaymentAttemptStatus::Pending,
            },
            initiatedBy: $initiator,
            failureCategory: $failed ? self::failureCategory($code) : null,
            providerCode: $failed ? $code : null,
            providerMessage: $failed ? SensitiveData::safeText(self::string($charge['failure_message'] ?? null)) : null,
            cardBrand: self::string($card['brand'] ?? null),
            cardLast4: $last4 !== null && preg_match('/^\d{4}$/', $last4) === 1 ? $last4 : null,
            providerCreatedAt: self::timestamp($charge['created'] ?? null),
        );
    }

    /**
     * Periodo de la mensualidad: inicio del periodo de la primera línea de la
     * factura (el periodo facturado). [S] Confirmar con Test Clocks.
     *
     * @param  array<string, mixed>  $invoice
     */
    private static function billingPeriodStart(array $invoice): ?CarbonImmutable
    {
        /** @var array<string, mixed> $lines */
        $lines = is_array($invoice['lines'] ?? null) ? $invoice['lines'] : [];
        /** @var list<array<string, mixed>> $data */
        $data = is_array($lines['data'] ?? null) ? array_values($lines['data']) : [];
        $period = isset($data[0]['period']) && is_array($data[0]['period']) ? $data[0]['period'] : [];
        $start = self::timestamp($period['start'] ?? null) ?? self::timestamp($invoice['period_start'] ?? null);

        return $start?->setTimezone(config()->string('app.timezone'))->startOfDay();
    }

    /**
     * @param  array<string, mixed>  $invoice
     */
    private static function paidAt(array $invoice): CarbonImmutable
    {
        $transitions = is_array($invoice['status_transitions'] ?? null) ? $invoice['status_transitions'] : [];

        return self::timestamp($transitions['paid_at'] ?? null) ?? CarbonImmutable::now();
    }

    /**
     * @param  list<array<string, mixed>>  $charges
     */
    private static function latestSuccess(array $charges): CarbonImmutable
    {
        foreach (array_reverse($charges) as $charge) {
            if (($charge['status'] ?? null) === 'succeeded') {
                return self::timestamp($charge['created'] ?? null) ?? CarbonImmutable::now();
            }
        }

        return CarbonImmutable::now();
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private static function metadataInt(array $object, string $key): ?int
    {
        return is_array($object['metadata'] ?? null) ? self::int($object['metadata'][$key] ?? null) : null;
    }

    private static function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function timestamp(mixed $value): ?CarbonImmutable
    {
        return is_int($value) && $value > 0 ? CarbonImmutable::createFromTimestamp($value) : null;
    }
}
