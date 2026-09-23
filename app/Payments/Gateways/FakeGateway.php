<?php

declare(strict_types=1);

namespace App\Payments\Gateways;

use App\Enums\AttemptInitiator;
use App\Enums\CardFunding;
use App\Enums\DisputeStatus;
use App\Enums\FailureCategory;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Enums\RefundReason;
use App\Enums\RefundStatus;
use App\Enums\RetryOwner;
use App\Enums\SubscriptionInterval;
use App\Enums\SubscriptionStatus;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Subscription;
use App\Payments\Contracts\PausesSubscriptions;
use App\Payments\Contracts\PaymentGateway;
use App\Payments\Contracts\ProcessesOneTimePayments;
use App\Payments\Contracts\ProcessesRecurringPayments;
use App\Payments\Contracts\ProcessesRefunds;
use App\Payments\Data\AmountLimits;
use App\Payments\Data\AttemptSnapshot;
use App\Payments\Data\CheckoutResult;
use App\Payments\Data\DisputeSnapshot;
use App\Payments\Data\InboundWebhook;
use App\Payments\Data\OneTimePaymentRequest;
use App\Payments\Data\PaymentSnapshot;
use App\Payments\Data\ProviderSnapshot;
use App\Payments\Data\RefundRequest;
use App\Payments\Data\RefundSnapshot;
use App\Payments\Data\SubscriptionRequest;
use App\Payments\Data\SubscriptionSnapshot;
use App\Payments\Exceptions\InvalidWebhookSignatureException;
use App\Payments\Exceptions\ProviderRejectedException;
use App\Payments\Exceptions\ProviderUnavailableException;
use App\Support\Money;
use App\Support\SensitiveData;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use LogicException;

/**
 * Pasarela simulada y determinista (fase-2-diseno-pagos.md §21). Nunca usa
 * Internet. Guarda su "estado del proveedor" en la caché configurada
 * (`payments.providers.fake.store`; "file" lo comparte entre procesos para
 * las pruebas de concurrencia).
 *
 * Las operaciones del contrato se comportan como un proveedor real:
 * idempotentes por llave, con fallos, timeouts y notificaciones. Los métodos
 * públicos adicionales (willReturn, chargeSubscription, openDispute…) son
 * los controles que usan las pruebas para simular lo que ocurre del lado
 * del proveedor.
 *
 * @phpstan-type AttemptState array{id: string, status: string, category: string|null, code: string|null, initiated_by: string, created_at: string}
 * @phpstan-type PaymentState array{kind: string, status: string, amount: string, crm_payment_id: int|null, subscription: string|null, period_start: string|null, succeeded_at: string|null, updated_at: string, attempts: list<AttemptState>}
 * @phpstan-type SubscriptionState array{status: string, amount: string, crm_subscription_id: int|null, updated_at: string, next_charge_at: string|null, cancelled_at: string|null}
 * @phpstan-type RefundState array{payment: string, amount: string, status: string, crm_refund_id: int|null, created_at: string, updated_at: string}
 * @phpstan-type DisputeState array{payment: string, status: string, amount: string, reason: string, created_at: string, updated_at: string}
 * @phpstan-type State array{payments: array<string, PaymentState>, subscriptions: array<string, SubscriptionState>, refunds: array<string, RefundState>, disputes: array<string, DisputeState>, idempotency: array<string, string>, scenarios: list<string>, unavailable: bool}
 */
final class FakeGateway implements PausesSubscriptions, PaymentGateway, ProcessesOneTimePayments, ProcessesRecurringPayments, ProcessesRefunds
{
    public const string SIGNATURE_HEADER = 'X-Fake-Signature';

    private const string STATE_KEY = 'fake-gateway:state';

    /**
     * Campos de la notificación simulada que se guardan (lista permitida).
     */
    public const array WEBHOOK_ALLOWLIST = [
        'id', 'type', 'created', 'resource_type', 'resource_id', 'data.object.id', 'data.object.status',
        'data.object.amount', 'data.object.metadata.crm_payment_id',
    ];

    public function provider(): PaymentProvider
    {
        return PaymentProvider::Fake;
    }

    public function isTestMode(): bool
    {
        return true;
    }

    public function amountLimits(): AmountLimits
    {
        $min = config('payments.providers.fake.min_amount');
        $max = config('payments.providers.fake.max_amount');

        return new AmountLimits(is_string($min) ? $min : null, is_string($max) ? $max : null, 'Configuración de pruebas');
    }

    // ---------------------------------------------------------------------
    // Controles para pruebas (simulan lo que ocurre en el proveedor)
    // ---------------------------------------------------------------------

    /**
     * Resultado de las siguientes operaciones, en orden. Sin escenario: éxito.
     */
    public function willReturn(FakeScenario ...$scenarios): self
    {
        $state = $this->state();
        $state['scenarios'] = [...$state['scenarios'], ...array_map(fn (FakeScenario $s): string => $s->value, array_values($scenarios))];
        $this->save($state);

        return $this;
    }

    /**
     * Mientras esté activo, toda llamada al proveedor falla por timeout.
     */
    public function setUnavailable(bool $unavailable = true): self
    {
        $state = $this->state();
        $state['unavailable'] = $unavailable;
        $this->save($state);

        return $this;
    }

    /**
     * El donante vuelve a intentar en la misma página (otra tarjeta).
     */
    public function donorAttempt(string $paymentId, FakeScenario $outcome): string
    {
        $state = $this->state();
        $payment = $state['payments'][$paymentId];
        $attemptId = $this->newId('ch');
        $payment['attempts'][] = $this->attempt($attemptId, $outcome, AttemptInitiator::Donor);
        $this->applyOutcome($payment, $outcome, retrying: true);
        $state['payments'][$paymentId] = $payment;
        $this->save($state);

        return $attemptId;
    }

    public function setPaymentStatus(string $paymentId, PaymentStatus $status): self
    {
        $state = $this->state();
        $payment = $state['payments'][$paymentId];
        $payment['status'] = $status->value;
        $payment['updated_at'] = $this->now();
        if ($status === PaymentStatus::Succeeded) {
            $payment['succeeded_at'] ??= $this->now();
        }
        $state['payments'][$paymentId] = $payment;
        $this->save($state);

        return $this;
    }

    /**
     * Cobro de una mensualidad (o un reintento del proveedor para ese periodo).
     * Con `exhausted` el proveedor deja de reintentar: la mensualidad queda fallida.
     *
     * @return string identificador del cobro de ese periodo
     */
    public function chargeSubscription(string $subscriptionId, CarbonImmutable $periodStart, FakeScenario $outcome, bool $exhausted = false): string
    {
        $state = $this->state();
        $period = $periodStart->toDateString();
        $paymentId = null;
        foreach ($state['payments'] as $id => $payment) {
            if ($payment['subscription'] === $subscriptionId && $payment['period_start'] === $period) {
                $paymentId = $id;
            }
        }

        $paymentId ??= $this->newId('inv');
        $payment = $state['payments'][$paymentId] ?? [
            'kind' => PaymentKind::RecurringCharge->value,
            'status' => PaymentStatus::Pending->value,
            'amount' => $state['subscriptions'][$subscriptionId]['amount'],
            'crm_payment_id' => null,
            'subscription' => $subscriptionId,
            'period_start' => $period,
            'succeeded_at' => null,
            'updated_at' => $this->now(),
            'attempts' => [],
        ];

        $payment['attempts'][] = $this->attempt($this->newId('ch'), $outcome, AttemptInitiator::Provider);
        $this->applyOutcome($payment, $outcome, retrying: ! $exhausted);
        $state['payments'][$paymentId] = $payment;
        $this->save($state);

        return $paymentId;
    }

    public function setSubscriptionStatus(string $subscriptionId, SubscriptionStatus $status): self
    {
        $state = $this->state();
        $subscription = $state['subscriptions'][$subscriptionId];
        $subscription['status'] = $status->value;
        $subscription['updated_at'] = $this->now();
        if ($status === SubscriptionStatus::Cancelled) {
            $subscription['cancelled_at'] = $this->now();
        }
        $state['subscriptions'][$subscriptionId] = $subscription;
        $this->save($state);

        return $this;
    }

    public function openDispute(string $paymentId, DisputeStatus $status = DisputeStatus::Open, ?string $amount = null): string
    {
        $state = $this->state();
        $disputeId = $this->newId('dp');
        $state['disputes'][$disputeId] = [
            'payment' => $paymentId,
            'status' => $status->value,
            'amount' => $amount ?? $state['payments'][$paymentId]['amount'],
            'reason' => 'fraudulent',
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ];
        $this->save($state);

        return $disputeId;
    }

    public function setDisputeStatus(string $disputeId, DisputeStatus $status): self
    {
        $state = $this->state();
        $dispute = $state['disputes'][$disputeId];
        $dispute['status'] = $status->value;
        $dispute['updated_at'] = $this->now();
        $state['disputes'][$disputeId] = $dispute;
        $this->save($state);

        return $this;
    }

    /**
     * Reembolso hecho directamente en el panel del proveedor.
     */
    public function providerRefund(string $paymentId, string $amount, RefundStatus $status = RefundStatus::Succeeded): string
    {
        $state = $this->state();
        $refundId = $this->newId('re');
        $state['refunds'][$refundId] = [
            'payment' => $paymentId, 'amount' => $amount, 'status' => $status->value, 'crm_refund_id' => null,
            'created_at' => $this->now(), 'updated_at' => $this->now(),
        ];
        $this->save($state);

        return $refundId;
    }

    public function setRefundStatus(string $refundId, RefundStatus $status): self
    {
        $state = $this->state();
        $refund = $state['refunds'][$refundId];
        $refund['status'] = $status->value;
        $refund['updated_at'] = $this->now();
        $state['refunds'][$refundId] = $refund;
        $this->save($state);

        return $this;
    }

    /**
     * Identificador externo del pago que el proveedor asoció a un Payment del CRM.
     */
    public function paymentIdFor(int $crmPaymentId): ?string
    {
        foreach ($this->state()['payments'] as $id => $payment) {
            if ($payment['crm_payment_id'] === $crmPaymentId) {
                return $id;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function refundIdsFor(string $paymentId): array
    {
        return array_keys(array_filter($this->state()['refunds'], fn (array $refund): bool => $refund['payment'] === $paymentId));
    }

    /**
     * Cuerpo y encabezados de una notificación firmada, como la enviaría el proveedor.
     *
     * @param  array<string, mixed>  $extra  campos adicionales (por ejemplo, datos sensibles para probar la sanitización)
     * @return array{body: string, headers: array<string, string>}
     */
    public function webhook(string $eventType, string $resourceType, string $resourceId, ?string $eventId = null, array $extra = []): array
    {
        $body = json_encode([
            'id' => $eventId ?? $this->newId('evt'),
            'type' => $eventType,
            'created' => time(),
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'data' => ['object' => ['id' => $resourceId]],
            ...$extra,
        ], JSON_THROW_ON_ERROR);

        return ['body' => $body, 'headers' => [self::SIGNATURE_HEADER => $this->sign($body), 'Content-Type' => 'application/json']];
    }

    public function reset(): void
    {
        $this->cache()->forget(self::STATE_KEY);
    }

    // ---------------------------------------------------------------------
    // Contrato
    // ---------------------------------------------------------------------

    public function parseWebhook(Request $request): InboundWebhook
    {
        $body = $request->getContent();
        $signature = (string) $request->header(self::SIGNATURE_HEADER, '');

        if ($signature === '' || ! hash_equals($this->sign($body), $signature)) {
            throw new InvalidWebhookSignatureException('Firma inválida.');
        }

        /** @var array<string, mixed> $data */
        $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        $created = $data['created'] ?? null;

        return new InboundWebhook(
            externalEventId: (string) ($data['id'] ?? ''),
            eventType: (string) ($data['type'] ?? 'unknown'),
            resourceType: is_string($data['resource_type'] ?? null) ? $data['resource_type'] : null,
            resourceExternalId: is_string($data['resource_id'] ?? null) ? $data['resource_id'] : null,
            payload: SensitiveData::allow($data, self::WEBHOOK_ALLOWLIST),
            providerCreatedAt: is_int($created) ? CarbonImmutable::createFromTimestamp($created) : null,
        );
    }

    public function fetch(string $resourceType, string $externalId): ProviderSnapshot
    {
        $this->guardAvailable();
        $state = $this->state();

        return match ($resourceType) {
            'payment' => isset($state['payments'][$externalId])
                ? new ProviderSnapshot(payments: [$this->paymentSnapshot($externalId, $state)])
                : new ProviderSnapshot,
            'subscription' => isset($state['subscriptions'][$externalId])
                ? new ProviderSnapshot(subscriptions: [$this->subscriptionSnapshot($externalId, $state)])
                : new ProviderSnapshot,
            'refund' => isset($state['refunds'][$externalId])
                ? new ProviderSnapshot(refunds: [$this->refundSnapshot($externalId, $state)])
                : new ProviderSnapshot,
            'dispute' => isset($state['disputes'][$externalId])
                ? new ProviderSnapshot(disputes: [$this->disputeSnapshot($externalId, $state)])
                : new ProviderSnapshot,
            default => new ProviderSnapshot,
        };
    }

    public function fetchPayment(Payment $payment): ProviderSnapshot
    {
        $id = $payment->external_id ?? $this->paymentIdFor($payment->id);

        return $id !== null ? $this->fetch('payment', $id) : new ProviderSnapshot;
    }

    public function fetchSubscription(Subscription $subscription): ProviderSnapshot
    {
        return $subscription->external_id !== null ? $this->fetch('subscription', $subscription->external_id) : new ProviderSnapshot;
    }

    public function fetchRefund(Refund $refund): ProviderSnapshot
    {
        return $refund->external_id !== null ? $this->fetch('refund', $refund->external_id) : new ProviderSnapshot;
    }

    public function startOneTimePayment(OneTimePaymentRequest $request): CheckoutResult
    {
        return $this->atomically(fn (): CheckoutResult => $this->processOneTimePayment($request));
    }

    private function processOneTimePayment(OneTimePaymentRequest $request): CheckoutResult
    {
        $scenario = $this->nextScenario();
        $this->guardAvailable($scenario);
        $state = $this->state();

        $paymentId = $state['idempotency'][$request->idempotencyKey] ?? null;
        if ($paymentId === null) {
            $paymentId = $this->newId('pay');
            $payment = [
                'kind' => PaymentKind::OneTime->value,
                'status' => PaymentStatus::Pending->value,
                'amount' => $request->amount,
                'crm_payment_id' => $request->paymentId,
                'subscription' => null,
                'period_start' => null,
                'succeeded_at' => null,
                'updated_at' => $this->now(),
                'attempts' => [],
            ];

            if ($scenario !== FakeScenario::Pending) {
                $payment['attempts'][] = $this->attempt($this->newId('ch'), $scenario, AttemptInitiator::Donor);
                $this->applyOutcome($payment, $scenario, retrying: $scenario !== FakeScenario::Failed);
            }

            $state['payments'][$paymentId] = $payment;
            $state['idempotency'][$request->idempotencyKey] = $paymentId;
            $this->save($state);
        }

        if ($scenario === FakeScenario::TimeoutAfterProcessing) {
            throw new ProviderUnavailableException(PaymentProvider::Fake, 'El proveedor simulado no respondió a tiempo.');
        }

        return new CheckoutResult(
            paymentExternalId: $paymentId,
            clientSecret: "{$paymentId}_secret",
            snapshot: $this->fetch('payment', $paymentId),
        );
    }

    public function supportedIntervals(): array
    {
        return [SubscriptionInterval::Monthly];
    }

    public function retryOwner(): RetryOwner
    {
        return RetryOwner::Provider;
    }

    public function startSubscription(SubscriptionRequest $request): CheckoutResult
    {
        return $this->atomically(fn (): CheckoutResult => $this->processSubscription($request));
    }

    private function processSubscription(SubscriptionRequest $request): CheckoutResult
    {
        $scenario = $this->nextScenario();
        $this->guardAvailable($scenario);
        $state = $this->state();

        $subscriptionId = $state['idempotency'][$request->idempotencyKey] ?? null;
        if ($subscriptionId === null) {
            $subscriptionId = $this->newId('sub');
            $active = $scenario !== FakeScenario::Pending;
            $state['subscriptions'][$subscriptionId] = [
                'status' => ($active ? SubscriptionStatus::Active : SubscriptionStatus::Pending)->value,
                'amount' => $request->amount,
                'crm_subscription_id' => $request->subscriptionId,
                'updated_at' => $this->now(),
                'next_charge_at' => CarbonImmutable::now()->addMonth()->startOfDay()->toIso8601String(),
                'cancelled_at' => null,
            ];
            $state['idempotency'][$request->idempotencyKey] = $subscriptionId;
            $this->save($state);

            if ($active) {
                $this->chargeSubscription($subscriptionId, CarbonImmutable::now()->startOfMonth(), $scenario);
            }
        }

        $snapshot = $this->fetch('subscription', $subscriptionId);
        $charges = array_keys(array_filter($this->state()['payments'], fn (array $p): bool => $p['subscription'] === $subscriptionId));
        $payments = array_map(fn (string $id): PaymentSnapshot => $this->paymentSnapshot($id, $this->state()), $charges);

        return new CheckoutResult(
            subscriptionExternalId: $subscriptionId,
            clientSecret: "{$subscriptionId}_secret",
            snapshot: new ProviderSnapshot(payments: $payments, subscriptions: $snapshot->subscriptions),
        );
    }

    public function cancelSubscription(string $externalId): SubscriptionSnapshot
    {
        return $this->changeSubscription($externalId, SubscriptionStatus::Cancelled);
    }

    public function pauseSubscription(string $externalId): SubscriptionSnapshot
    {
        return $this->changeSubscription($externalId, SubscriptionStatus::Paused);
    }

    public function resumeSubscription(string $externalId): SubscriptionSnapshot
    {
        return $this->changeSubscription($externalId, SubscriptionStatus::Active);
    }

    public function providerRefundReason(RefundReason $reason): ?string
    {
        return match ($reason) {
            RefundReason::DonorRequest => 'requested_by_customer',
            RefundReason::DuplicateCharge => 'duplicate',
            default => null,
        };
    }

    public function refund(RefundRequest $request): RefundSnapshot
    {
        return $this->atomically(fn (): RefundSnapshot => $this->processRefund($request));
    }

    private function processRefund(RefundRequest $request): RefundSnapshot
    {
        $scenario = $this->nextScenario();
        $this->guardAvailable($scenario);
        $state = $this->state();

        $refundId = $state['idempotency'][$request->idempotencyKey] ?? null;
        if ($refundId === null) {
            $paymentId = $request->paymentExternalId ?? '';
            if (! isset($state['payments'][$paymentId])) {
                throw new ProviderRejectedException(PaymentProvider::Fake, 'El pago no existe en el proveedor simulado.', 'resource_missing');
            }

            $refunded = '0';
            foreach ($state['refunds'] as $existing) {
                if ($existing['payment'] === $paymentId && in_array($existing['status'], ['pending', 'succeeded'], true)) {
                    $refunded = Money::add($refunded, $existing['amount']);
                }
            }

            if ($scenario === FakeScenario::Rejected || Money::compare(Money::add($refunded, $request->amount), $state['payments'][$paymentId]['amount']) > 0) {
                throw new ProviderRejectedException(PaymentProvider::Fake, 'El proveedor rechazó el reembolso.', 'refund_rejected');
            }

            $refundId = $this->newId('re');
            $state['refunds'][$refundId] = [
                'payment' => $paymentId,
                'amount' => $request->amount,
                'status' => ($scenario === FakeScenario::Pending ? RefundStatus::Pending : RefundStatus::Succeeded)->value,
                'crm_refund_id' => $request->refundId,
                'created_at' => $this->now(),
                'updated_at' => $this->now(),
            ];
            $state['idempotency'][$request->idempotencyKey] = $refundId;
            $this->save($state);
        }

        if ($scenario === FakeScenario::TimeoutAfterProcessing) {
            throw new ProviderUnavailableException(PaymentProvider::Fake, 'El proveedor simulado no respondió a tiempo.');
        }

        return $this->refundSnapshot($refundId, $this->state());
    }

    // ---------------------------------------------------------------------
    // Internos
    // ---------------------------------------------------------------------

    private function changeSubscription(string $externalId, SubscriptionStatus $status): SubscriptionSnapshot
    {
        $this->guardAvailable($this->nextScenario());
        $this->setSubscriptionStatus($externalId, $status);

        return $this->subscriptionSnapshot($externalId, $this->state());
    }

    /**
     * @param  PaymentState  $payment
     */
    private function applyOutcome(array &$payment, FakeScenario $outcome, bool $retrying): void
    {
        $payment['updated_at'] = $this->now();
        $payment['status'] = match ($outcome) {
            FakeScenario::Success, FakeScenario::TimeoutAfterProcessing => PaymentStatus::Succeeded->value,
            FakeScenario::Processing => PaymentStatus::Processing->value,
            FakeScenario::Declined, FakeScenario::ExpiredCard, FakeScenario::InsufficientFunds, FakeScenario::Failed => $retrying
                ? PaymentStatus::Pending->value
                : PaymentStatus::Failed->value,
            default => $payment['status'],
        };

        if ($payment['status'] === PaymentStatus::Succeeded->value) {
            $payment['succeeded_at'] ??= $this->now();
        }
    }

    /**
     * @return AttemptState
     */
    private function attempt(string $id, FakeScenario $outcome, AttemptInitiator $initiator): array
    {
        $status = match ($outcome) {
            FakeScenario::Success, FakeScenario::TimeoutAfterProcessing => PaymentAttemptStatus::Succeeded,
            FakeScenario::Processing, FakeScenario::Pending => PaymentAttemptStatus::Pending,
            default => PaymentAttemptStatus::Failed,
        };

        return [
            'id' => $id,
            'status' => $status->value,
            'category' => $status === PaymentAttemptStatus::Failed ? ($outcome->failureCategory() ?? FakeScenario::Failed->failureCategory())?->value : null,
            'code' => $status === PaymentAttemptStatus::Failed ? ($outcome->providerCode() ?? 'processing_error') : null,
            'initiated_by' => $initiator->value,
            'created_at' => $this->now(),
        ];
    }

    /**
     * @param  State  $state
     */
    private function paymentSnapshot(string $id, array $state): PaymentSnapshot
    {
        $payment = $state['payments'][$id];
        $subscription = $payment['subscription'] !== null ? ($state['subscriptions'][$payment['subscription']] ?? null) : null;
        $retrying = $payment['kind'] === PaymentKind::RecurringCharge->value && $payment['status'] === PaymentStatus::Pending->value
            && $payment['attempts'] !== [];

        return new PaymentSnapshot(
            kind: PaymentKind::from($payment['kind']),
            status: PaymentStatus::from($payment['status']),
            providerStatus: $payment['status'],
            externalId: $id,
            crmPaymentId: $payment['crm_payment_id'],
            amount: $payment['amount'],
            providerUpdatedAt: CarbonImmutable::parse($payment['updated_at']),
            succeededAt: $payment['succeeded_at'] !== null ? CarbonImmutable::parse($payment['succeeded_at']) : null,
            subscriptionExternalId: $payment['subscription'],
            crmSubscriptionId: $subscription['crm_subscription_id'] ?? null,
            billingPeriodStart: $payment['period_start'] !== null ? CarbonImmutable::parse($payment['period_start']) : null,
            attempts: array_map(fn (array $attempt): AttemptSnapshot => new AttemptSnapshot(
                externalId: $attempt['id'],
                status: PaymentAttemptStatus::from($attempt['status']),
                initiatedBy: AttemptInitiator::from($attempt['initiated_by']),
                failureCategory: $attempt['category'] !== null ? FailureCategory::from($attempt['category']) : null,
                providerCode: $attempt['code'],
                providerMessage: $attempt['code'] !== null ? 'Rechazo simulado' : null,
                cardBrand: 'visa',
                cardLast4: '4242',
                cardFunding: CardFunding::Credit,
                providerCreatedAt: CarbonImmutable::parse($attempt['created_at']),
            ), $payment['attempts']),
            refunds: array_map(fn (string $refundId): RefundSnapshot => $this->refundSnapshot($refundId, $state), $this->refundIdsFor($id)),
            nextRetryOwner: $retrying ? RetryOwner::Provider : null,
            nextRetryAt: $retrying ? CarbonImmutable::now()->addDays(3) : null,
        );
    }

    /**
     * @param  State  $state
     */
    private function subscriptionSnapshot(string $id, array $state): SubscriptionSnapshot
    {
        $subscription = $state['subscriptions'][$id];

        return new SubscriptionSnapshot(
            status: SubscriptionStatus::from($subscription['status']),
            providerStatus: $subscription['status'],
            externalId: $id,
            crmSubscriptionId: $subscription['crm_subscription_id'],
            amount: $subscription['amount'],
            providerUpdatedAt: CarbonImmutable::parse($subscription['updated_at']),
            nextChargeAt: $subscription['next_charge_at'] !== null ? CarbonImmutable::parse($subscription['next_charge_at']) : null,
            cancelledAt: $subscription['cancelled_at'] !== null ? CarbonImmutable::parse($subscription['cancelled_at']) : null,
        );
    }

    /**
     * @param  State  $state
     */
    private function refundSnapshot(string $id, array $state): RefundSnapshot
    {
        $refund = $state['refunds'][$id];

        return new RefundSnapshot(
            externalId: $id,
            status: RefundStatus::from($refund['status']),
            amount: $refund['amount'],
            paymentExternalId: $refund['payment'],
            crmPaymentId: $state['payments'][$refund['payment']]['crm_payment_id'] ?? null,
            crmRefundId: $refund['crm_refund_id'],
            failureReason: $refund['status'] === RefundStatus::Failed->value ? 'Reembolso rechazado por el banco (simulado).' : null,
            providerCreatedAt: CarbonImmutable::parse($refund['created_at']),
            providerUpdatedAt: CarbonImmutable::parse($refund['updated_at']),
        );
    }

    /**
     * @param  State  $state
     */
    private function disputeSnapshot(string $id, array $state): DisputeSnapshot
    {
        $dispute = $state['disputes'][$id];
        $status = DisputeStatus::from($dispute['status']);

        return new DisputeSnapshot(
            externalId: $id,
            status: $status,
            providerStatus: $dispute['status'],
            paymentExternalId: $dispute['payment'],
            amount: $dispute['amount'],
            providerReason: $dispute['reason'],
            openedAt: CarbonImmutable::parse($dispute['created_at']),
            evidenceDueAt: CarbonImmutable::parse($dispute['created_at'])->addDays(7),
            closedAt: $status->isOpen() ? null : CarbonImmutable::parse($dispute['updated_at']),
            providerUpdatedAt: CarbonImmutable::parse($dispute['updated_at']),
        );
    }

    /**
     * Como un proveedor real, la idempotencia es atómica: dos llamadas
     * simultáneas con la misma llave obtienen el mismo resultado (también
     * entre procesos cuando la caché es "file").
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function atomically(callable $callback): mixed
    {
        $store = $this->cache()->getStore();
        if (! $store instanceof LockProvider) {
            throw new LogicException('El almacén de la pasarela simulada debe admitir candados (array o file).');
        }

        return $store->lock(self::STATE_KEY.':lock', 10)->block(10, $callback);
    }

    private function nextScenario(): FakeScenario
    {
        $state = $this->state();
        $next = array_shift($state['scenarios']);
        $this->save($state);

        return $next !== null ? FakeScenario::from($next) : FakeScenario::Success;
    }

    private function guardAvailable(?FakeScenario $scenario = null): void
    {
        if ($scenario === FakeScenario::ProviderUnavailable || $this->state()['unavailable']) {
            throw new ProviderUnavailableException(PaymentProvider::Fake, 'El proveedor simulado no está disponible.');
        }
    }

    private function sign(string $body): string
    {
        return hash_hmac('sha256', $body, config()->string('payments.providers.fake.webhook_secret'));
    }

    private function newId(string $prefix): string
    {
        return "fake_{$prefix}_".Str::lower(Str::random(14));
    }

    /**
     * Marca de tiempo con microsegundos: dos cambios seguidos se distinguen.
     */
    private function now(): string
    {
        return CarbonImmutable::now()->format('Y-m-d\TH:i:s.uP');
    }

    /**
     * @return State
     */
    private function state(): array
    {
        /** @var State|null $state */
        $state = $this->cache()->get(self::STATE_KEY);

        return $state ?? [
            'payments' => [], 'subscriptions' => [], 'refunds' => [], 'disputes' => [],
            'idempotency' => [], 'scenarios' => [], 'unavailable' => false,
        ];
    }

    /**
     * @param  State  $state
     */
    private function save(array $state): void
    {
        $this->cache()->forever(self::STATE_KEY, $state);
    }

    private function cache(): Repository
    {
        return Cache::store(config()->string('payments.providers.fake.store'));
    }
}
