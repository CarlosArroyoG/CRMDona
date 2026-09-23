<?php

declare(strict_types=1);

namespace App\Payments\Gateways\MercadoPago;

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
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Traduce recursos de Mercado Pago (arreglos JSON) al modelo interno.
 *
 * [V] Estados de payment (approved, in_process, rejected, cancelled,
 * refunded, charged_back…), preapproval (pending, authorized, paused,
 * cancelled) y la notificación firmada: documentación oficial de Mercado
 * Pago México (fase-2-diseno-pagos.md, fuentes).
 * [S] Estados y campos de Orders, authorized_payments y chargebacks: se
 * confirman en sandbox antes de cerrar el adaptador (checklist §16.3).
 */
final class MercadoPagoMapper
{
    /**
     * Campos de la notificación y del recurso que se guardan (§18.1).
     */
    public const array WEBHOOK_ALLOWLIST = [
        'id', 'type', 'topic', 'action', 'api_version', 'date_created', 'live_mode', 'user_id', 'data.id',
        'headers.x-request-id', 'headers.ts',
    ];

    /**
     * Prefijos de external_reference: así el CRM reconoce sus propios recursos.
     */
    public const string PAYMENT_REFERENCE = 'crm-payment-';

    public const string SUBSCRIPTION_REFERENCE = 'crm-subscription-';

    /**
     * @return array{0: string, 1: string}|null
     */
    public static function route(string $type, ?string $resourceId): ?array
    {
        if ($resourceId === null || $resourceId === '') {
            return null;
        }

        return match ($type) {
            'order' => ['order', $resourceId],
            'payment' => ['payment', $resourceId],
            'subscription_preapproval' => ['preapproval', $resourceId],
            'subscription_authorized_payment' => ['authorized_payment', $resourceId],
            'topic_chargebacks_wh', 'chargebacks' => ['chargeback', $resourceId],
            default => null,
        };
    }

    /**
     * Pago único con la API de Orders. [S] estados de la Order.
     *
     * @param  array<string, mixed>  $order
     */
    public static function order(array $order): PaymentSnapshot
    {
        $status = self::string($order['status'] ?? null) ?? 'created';
        $payments = self::transactions($order, 'payments');
        $refunds = self::transactions($order, 'refunds');

        $mapped = match ($status) {
            'processed', 'refunded', 'partially_refunded' => PaymentStatus::Succeeded,
            'processing', 'in_process' => PaymentStatus::Processing,
            'failed' => PaymentStatus::Failed,
            'canceled', 'cancelled', 'expired' => PaymentStatus::Cancelled,
            // created, action_required
            default => PaymentStatus::Pending,
        };

        return new PaymentSnapshot(
            kind: PaymentKind::OneTime,
            status: $mapped,
            providerStatus: $status.(($detail = self::string($order['status_detail'] ?? null)) !== null ? ":{$detail}" : ''),
            externalId: self::string($order['id'] ?? null),
            crmPaymentId: self::reference($order['external_reference'] ?? null, self::PAYMENT_REFERENCE),
            amount: self::amount($order['total_amount'] ?? null),
            providerUpdatedAt: self::date($order['last_updated_date'] ?? null),
            succeededAt: $mapped === PaymentStatus::Succeeded ? (self::date($order['last_updated_date'] ?? null) ?? CarbonImmutable::now()) : null,
            attempts: array_map(fn (array $payment): AttemptSnapshot => self::attempt($payment, AttemptInitiator::Donor), $payments),
            refunds: array_values(array_filter(array_map(
                fn (array $refund): ?RefundSnapshot => self::orderRefund($refund, self::string($order['id'] ?? null)),
                $refunds,
            ))),
        );
    }

    /**
     * @param  array<string, mixed>  $preapproval
     */
    public static function preapproval(array $preapproval): SubscriptionSnapshot
    {
        $status = self::string($preapproval['status'] ?? null) ?? 'pending';
        /** @var array<string, mixed> $recurring */
        $recurring = is_array($preapproval['auto_recurring'] ?? null) ? $preapproval['auto_recurring'] : [];

        return new SubscriptionSnapshot(
            status: match ($status) {
                'authorized' => SubscriptionStatus::Active,
                'paused' => SubscriptionStatus::Paused,
                'cancelled' => SubscriptionStatus::Cancelled,
                default => SubscriptionStatus::Pending,
            },
            providerStatus: $status,
            externalId: self::string($preapproval['id'] ?? null),
            crmSubscriptionId: self::reference($preapproval['external_reference'] ?? null, self::SUBSCRIPTION_REFERENCE),
            amount: self::amount($recurring['transaction_amount'] ?? null),
            providerUpdatedAt: self::date($preapproval['last_modified'] ?? null),
            nextChargeAt: self::date($preapproval['next_payment_date'] ?? null),
            startedAt: self::date($preapproval['date_created'] ?? null),
        );
    }

    /**
     * Cuota de una suscripción (authorized_payment): es el Payment del
     * periodo. El proveedor la reintenta ("recycling"). [S] campos exactos.
     *
     * @param  array<string, mixed>  $authorized
     */
    public static function authorizedPayment(array $authorized): PaymentSnapshot
    {
        $status = self::string($authorized['status'] ?? null) ?? 'scheduled';
        /** @var array<string, mixed> $payment */
        $payment = is_array($authorized['payment'] ?? null) ? $authorized['payment'] : [];
        $paymentStatus = self::string($payment['status'] ?? null);

        $mapped = match (true) {
            $status === 'processed' && $paymentStatus === 'approved' => PaymentStatus::Succeeded,
            $status === 'processed' => PaymentStatus::Failed,
            $status === 'cancelled' => PaymentStatus::Cancelled,
            // scheduled, recycling
            default => PaymentStatus::Pending,
        };
        $retrying = $status === 'recycling';
        $attempts = isset($payment['id']) ? [self::attempt($payment, AttemptInitiator::Provider)] : [];

        return new PaymentSnapshot(
            kind: PaymentKind::RecurringCharge,
            status: $mapped,
            providerStatus: $status.($paymentStatus !== null ? ":{$paymentStatus}" : ''),
            externalId: self::string(isset($authorized['id']) ? (string) $authorized['id'] : null),
            amount: self::amount($authorized['transaction_amount'] ?? null),
            providerUpdatedAt: self::date($authorized['last_modified'] ?? null),
            succeededAt: $mapped === PaymentStatus::Succeeded ? (self::date($authorized['last_modified'] ?? null) ?? CarbonImmutable::now()) : null,
            subscriptionExternalId: self::string($authorized['preapproval_id'] ?? null),
            billingPeriodStart: self::date($authorized['debit_date'] ?? null)?->setTimezone(config()->string('app.timezone'))->startOfDay(),
            attempts: $attempts,
            nextRetryOwner: $retrying ? RetryOwner::Provider : null,
            nextRetryAt: $retrying ? self::date($authorized['next_retry_date'] ?? null) : null,
        );
    }

    /**
     * Contracargo. [S] La documentación muestra `documentation_status` y
     * `coverage_applied`; la correspondencia exacta se confirma en sandbox.
     *
     * @param  array<string, mixed>  $chargeback
     */
    public static function chargeback(array $chargeback): DisputeSnapshot
    {
        $documentation = self::string($chargeback['documentation_status'] ?? null);
        $coverage = $chargeback['coverage_applied'] ?? null;
        $status = match (true) {
            $coverage === true => DisputeStatus::Won,
            $coverage === false => DisputeStatus::Lost,
            $documentation === 'review_pending' => DisputeStatus::UnderReview,
            default => DisputeStatus::Open,
        };
        $payments = is_array($chargeback['payments'] ?? null) ? array_values($chargeback['payments']) : [];
        $firstPayment = $payments[0] ?? null;

        return new DisputeSnapshot(
            externalId: (string) ($chargeback['id'] ?? ''),
            status: $status,
            providerStatus: $documentation ?? 'unknown',
            attemptExternalId: is_scalar($firstPayment) ? (string) $firstPayment : null,
            amount: self::amount($chargeback['amount'] ?? null),
            openedAt: self::date($chargeback['date_created'] ?? null),
            evidenceDueAt: self::date($chargeback['date_documentation_deadline'] ?? null),
            providerUpdatedAt: self::date($chargeback['last_modified'] ?? null),
        );
    }

    /**
     * `status_detail` de rechazo → categoría interna. Se compara por
     * contenido para aceptar tanto `cc_rejected_*` (Payments API) como los
     * nombres cortos de Orders. [S] lista exacta de Orders.
     */
    public static function failureCategory(?string $detail): FailureCategory
    {
        $detail = strtolower((string) $detail);

        return match (true) {
            $detail === '' => FailureCategory::Unknown,
            str_contains($detail, 'insufficient_amount') => FailureCategory::InsufficientFunds,
            str_contains($detail, 'bad_filled'), str_contains($detail, 'invalid_card') => FailureCategory::InvalidPaymentData,
            str_contains($detail, 'expired') => FailureCategory::ExpiredCard,
            str_contains($detail, 'high_risk'), str_contains($detail, 'fraud'), str_contains($detail, 'blacklist') => FailureCategory::SuspectedFraud,
            str_contains($detail, 'duplicated') => FailureCategory::Duplicate,
            str_contains($detail, 'max_attempts') => FailureCategory::TooManyAttempts,
            str_contains($detail, 'call_for_authorize'), str_contains($detail, 'challenge') => FailureCategory::AuthenticationFailed,
            str_contains($detail, 'card_error'), str_contains($detail, 'processing') => FailureCategory::ProcessingError,
            default => FailureCategory::Declined,
        };
    }

    /**
     * @return numeric-string|null
     */
    public static function amount(mixed $value): ?string
    {
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
            return bcadd(is_float($value) ? number_format($value, 2, '.', '') : (string) $value, '0', 2);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payment
     */
    private static function attempt(array $payment, AttemptInitiator $initiator): AttemptSnapshot
    {
        $status = self::string($payment['status'] ?? null);
        $detail = self::string($payment['status_detail'] ?? null);
        $failed = in_array($status, ['rejected', 'failed', 'cancelled'], true);
        /** @var array<string, mixed> $method */
        $method = is_array($payment['payment_method'] ?? null) ? $payment['payment_method'] : [];
        /** @var array<string, mixed> $card */
        $card = is_array($payment['card'] ?? null) ? $payment['card'] : [];
        $last4 = self::string($card['last_four_digits'] ?? null);

        return new AttemptSnapshot(
            externalId: (string) ($payment['id'] ?? ''),
            status: match (true) {
                in_array($status, ['approved', 'processed', 'accredited'], true) => PaymentAttemptStatus::Succeeded,
                $failed => PaymentAttemptStatus::Failed,
                default => PaymentAttemptStatus::Pending,
            },
            initiatedBy: $initiator,
            failureCategory: $failed ? self::failureCategory($detail) : null,
            providerCode: $failed ? $detail : null,
            cardBrand: self::string($method['id'] ?? null) ?? self::string($payment['payment_method_id'] ?? null),
            cardLast4: $last4 !== null && preg_match('/^\d{4}$/', $last4) === 1 ? $last4 : null,
            providerCreatedAt: self::date($payment['date_created'] ?? null),
        );
    }

    /**
     * @param  array<string, mixed>  $refund
     */
    private static function orderRefund(array $refund, ?string $orderId): ?RefundSnapshot
    {
        $id = isset($refund['id']) ? (string) $refund['id'] : null;
        if ($id === null) {
            return null;
        }

        $status = self::string($refund['status'] ?? null) ?? 'processing';

        return new RefundSnapshot(
            externalId: $id,
            status: match ($status) {
                'processed', 'approved', 'refunded' => RefundStatus::Succeeded,
                'failed', 'rejected' => RefundStatus::Failed,
                'cancelled', 'canceled' => RefundStatus::Cancelled,
                default => RefundStatus::Pending,
            },
            amount: self::amount($refund['amount'] ?? null) ?? '0.00',
            paymentExternalId: $orderId,
            providerCreatedAt: self::date($refund['date_created'] ?? null),
        );
    }

    /**
     * @param  array<string, mixed>  $order
     * @return list<array<string, mixed>>
     */
    private static function transactions(array $order, string $key): array
    {
        $transactions = is_array($order['transactions'] ?? null) ? $order['transactions'] : [];
        $items = is_array($transactions[$key] ?? null) ? $transactions[$key] : [];

        /** @var list<array<string, mixed>> $list */
        $list = array_values(array_filter($items, 'is_array'));

        return $list;
    }

    private static function reference(mixed $value, string $prefix): ?int
    {
        return is_string($value) && str_starts_with($value, $prefix) && is_numeric($id = substr($value, strlen($prefix)))
            ? (int) $id
            : null;
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
