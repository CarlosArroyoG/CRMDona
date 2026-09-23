<?php

declare(strict_types=1);

namespace App\Payments\Gateways\Stripe;

use App\Enums\FailureCategory;
use App\Enums\PaymentKind;
use App\Enums\PaymentProvider;
use App\Enums\RefundReason;
use App\Enums\RetryOwner;
use App\Enums\SubscriptionInterval;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Subscription;
use App\Payments\Contracts\PausesSubscriptions;
use App\Payments\Contracts\PaymentGateway;
use App\Payments\Contracts\ProcessesOneTimePayments;
use App\Payments\Contracts\ProcessesRecurringPayments;
use App\Payments\Contracts\ProcessesRefunds;
use App\Payments\Data\AmountLimits;
use App\Payments\Data\CheckoutResult;
use App\Payments\Data\InboundWebhook;
use App\Payments\Data\OneTimePaymentRequest;
use App\Payments\Data\ProviderSnapshot;
use App\Payments\Data\RefundRequest;
use App\Payments\Data\RefundSnapshot;
use App\Payments\Data\SubscriptionRequest;
use App\Payments\Data\SubscriptionSnapshot;
use App\Payments\Exceptions\GatewayNotAvailableException;
use App\Payments\Exceptions\InvalidWebhookSignatureException;
use App\Payments\Exceptions\PaymentProviderException;
use App\Payments\Exceptions\ProviderRejectedException;
use App\Payments\Exceptions\ProviderUnavailableException;
use App\Support\SensitiveData;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\CardException;
use Stripe\Exception\RateLimitException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\StripeObject;
use Stripe\Webhook;
use UnexpectedValueException;

/**
 * Adaptador de Stripe (ADR-011). El SDK oficial solo se usa aquí: el
 * dominio recibe snapshots normalizados.
 *
 * - Pago único y mensual con Checkout Sessions en modo embebido
 *   (`ui_mode: embedded_page` en la API 2026-08-26.dahlia).
 * - Reintentos: Smart Retries, a cargo de Stripe (RetryOwner::Provider).
 * - Cada POST lleva la llave de idempotencia del CRM.
 *
 * Todo lo marcado [S] en fase-2-diseno-pagos.md se confirma en sandbox.
 */
final class StripeGateway implements PausesSubscriptions, PaymentGateway, ProcessesOneTimePayments, ProcessesRecurringPayments, ProcessesRefunds
{
    private readonly StripeClient $client;

    private readonly string $webhookSecret;

    private readonly bool $testMode;

    /**
     * @throws GatewayNotAvailableException si falta configuración o el modo no coincide con la llave
     */
    public function __construct()
    {
        $secret = config('payments.providers.stripe.secret_key');
        $webhookSecret = config('payments.providers.stripe.webhook_secret');
        $mode = config('payments.providers.stripe.mode');

        if (! is_string($secret) || $secret === '' || ! is_string($webhookSecret) || $webhookSecret === '') {
            throw new GatewayNotAvailableException('Stripe no está configurado (faltan STRIPE_SECRET_KEY o STRIPE_WEBHOOK_SECRET).');
        }

        $keyIsTest = str_starts_with($secret, 'sk_test_') || str_starts_with($secret, 'rk_test_');
        $keyIsLive = str_starts_with($secret, 'sk_live_') || str_starts_with($secret, 'rk_live_');
        if (($mode === 'test' && ! $keyIsTest) || ($mode === 'live' && ! $keyIsLive) || ! in_array($mode, ['test', 'live'], true)) {
            throw new GatewayNotAvailableException('STRIPE_MODE no coincide con el tipo de llave configurada.');
        }

        $this->client = new StripeClient(['api_key' => $secret, 'max_network_retries' => 2]);
        $this->webhookSecret = $webhookSecret;
        $this->testMode = $mode === 'test';
    }

    public function provider(): PaymentProvider
    {
        return PaymentProvider::Stripe;
    }

    public function isTestMode(): bool
    {
        return $this->testMode;
    }

    /**
     * [V] Mínimo por cargo en MXN: 10.00 (docs.stripe.com/currencies). El
     * máximo lo fija la red de la tarjeta; no hay uno verificado que aplicar.
     */
    public function amountLimits(): AmountLimits
    {
        return new AmountLimits('10.00', null, 'Stripe: mínimo 10 MXN por cargo');
    }

    public function parseWebhook(Request $request): InboundWebhook
    {
        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                (string) $request->header('Stripe-Signature', ''),
                $this->webhookSecret,
                config()->integer('payments.providers.stripe.webhook_tolerance'),
            );
        } catch (SignatureVerificationException|UnexpectedValueException) {
            throw new InvalidWebhookSignatureException('Firma de Stripe inválida.');
        }

        /** @var array<string, mixed> $data */
        $data = $event->toArray();
        /** @var array<string, mixed> $object */
        $object = is_array($data['data']['object'] ?? null) ? $data['data']['object'] : [];
        $route = StripeMapper::route((string) $event->type, $object);

        return new InboundWebhook(
            externalEventId: (string) $event->id,
            eventType: (string) $event->type,
            resourceType: $route[0] ?? null,
            resourceExternalId: $route[1] ?? null,
            payload: SensitiveData::allow($data, StripeMapper::WEBHOOK_ALLOWLIST),
            providerCreatedAt: is_int($data['created'] ?? null) ? CarbonImmutable::createFromTimestamp($data['created']) : null,
        );
    }

    public function fetch(string $resourceType, string $externalId): ProviderSnapshot
    {
        return $this->call(fn (): ProviderSnapshot => match ($resourceType) {
            'checkout_session' => $this->fetchCheckoutSession($externalId),
            'payment_intent' => $this->fetchPaymentIntent($externalId),
            'invoice' => $this->fetchInvoice($externalId),
            'subscription' => $this->fetchStripeSubscription($externalId),
            'refund' => new ProviderSnapshot(refunds: [StripeMapper::refund($this->array($this->client->refunds->retrieve($externalId)))]),
            'dispute' => new ProviderSnapshot(disputes: [StripeMapper::dispute($this->array($this->client->disputes->retrieve($externalId)))]),
            default => new ProviderSnapshot,
        });
    }

    public function fetchPayment(Payment $payment): ProviderSnapshot
    {
        if ($payment->external_id === null) {
            return new ProviderSnapshot;
        }

        return $this->fetch($payment->kind === PaymentKind::RecurringCharge ? 'invoice' : 'payment_intent', $payment->external_id);
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
        $session = $this->call(fn (): StripeObject => $this->client->checkout->sessions->create([
            'mode' => 'payment',
            'ui_mode' => 'embedded_page',
            'return_url' => $request->returnUrl.'?session_id={CHECKOUT_SESSION_ID}',
            'payment_method_types' => ['card'],
            'locale' => 'es-419',
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => 'mxn',
                    'unit_amount' => StripeMapper::minorUnits($request->amount),
                    'product_data' => ['name' => $request->description],
                ],
            ]],
            ...($request->donorEmail !== null ? ['customer_email' => $request->donorEmail] : []),
            'client_reference_id' => (string) $request->paymentId,
            'metadata' => ['crm_payment_id' => (string) $request->paymentId],
            'payment_intent_data' => [
                'description' => $request->description,
                'metadata' => ['crm_payment_id' => (string) $request->paymentId],
            ],
        ], ['idempotency_key' => $request->idempotencyKey]));

        $data = $this->array($session);

        return new CheckoutResult(
            paymentExternalId: is_string($data['payment_intent'] ?? null) ? $data['payment_intent'] : null,
            clientSecret: is_string($data['client_secret'] ?? null) ? $data['client_secret'] : null,
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
        $session = $this->call(fn (): StripeObject => $this->client->checkout->sessions->create([
            'mode' => 'subscription',
            'ui_mode' => 'embedded_page',
            'return_url' => $request->returnUrl.'?session_id={CHECKOUT_SESSION_ID}',
            'payment_method_types' => ['card'],
            'locale' => 'es-419',
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => 'mxn',
                    'unit_amount' => StripeMapper::minorUnits($request->amount),
                    'recurring' => ['interval' => 'month'],
                    'product_data' => ['name' => $request->description],
                ],
            ]],
            ...($request->donorEmail !== null ? ['customer_email' => $request->donorEmail] : []),
            'client_reference_id' => (string) $request->subscriptionId,
            'metadata' => ['crm_subscription_id' => (string) $request->subscriptionId],
            'subscription_data' => [
                'description' => $request->description,
                'metadata' => ['crm_subscription_id' => (string) $request->subscriptionId],
            ],
        ], ['idempotency_key' => $request->idempotencyKey]));

        $data = $this->array($session);

        return new CheckoutResult(
            subscriptionExternalId: is_string($data['subscription'] ?? null) ? $data['subscription'] : null,
            clientSecret: is_string($data['client_secret'] ?? null) ? $data['client_secret'] : null,
        );
    }

    public function cancelSubscription(string $externalId): SubscriptionSnapshot
    {
        return StripeMapper::subscription($this->array($this->call(fn (): StripeObject => $this->client->subscriptions->cancel($externalId))));
    }

    /**
     * Pausa el cobro (`pause_collection`). Con `void` Stripe anula las
     * facturas del periodo pausado: el donante no acumula adeudo.
     * [D] Comportamiento a confirmar por la organización.
     */
    public function pauseSubscription(string $externalId): SubscriptionSnapshot
    {
        return StripeMapper::subscription($this->array($this->call(fn (): StripeObject => $this->client->subscriptions->update(
            $externalId,
            ['pause_collection' => ['behavior' => 'void']],
        ))));
    }

    public function resumeSubscription(string $externalId): SubscriptionSnapshot
    {
        return StripeMapper::subscription($this->array($this->call(fn (): StripeObject => $this->client->subscriptions->update(
            $externalId,
            ['pause_collection' => null],
        ))));
    }

    /**
     * [V] Stripe acepta `duplicate`, `fraudulent` y `requested_by_customer`.
     */
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
        // Mensualidades: el pago es la factura; se reembolsa el cargo exitoso.
        $target = $request->attemptExternalId !== null
            ? ['charge' => $request->attemptExternalId]
            : ['payment_intent' => (string) $request->paymentExternalId];

        $refund = $this->call(fn (): StripeObject => $this->client->refunds->create([
            ...$target,
            'amount' => StripeMapper::minorUnits($request->amount),
            ...($request->providerReason !== null ? ['reason' => $request->providerReason] : []),
            'metadata' => ['crm_refund_id' => (string) $request->refundId, 'crm_payment_id' => (string) $request->paymentId],
        ], ['idempotency_key' => $request->idempotencyKey]));

        return StripeMapper::refund($this->array($refund));
    }

    private function fetchCheckoutSession(string $id): ProviderSnapshot
    {
        $session = $this->array($this->client->checkout->sessions->retrieve($id));
        $intent = is_string($session['payment_intent'] ?? null) ? $session['payment_intent'] : null;
        $subscription = is_string($session['subscription'] ?? null) ? $session['subscription'] : null;

        return match (true) {
            $intent !== null => $this->fetchPaymentIntent($intent),
            $subscription !== null => $this->fetchStripeSubscription($subscription),
            ($session['mode'] ?? null) === 'subscription' && ($session['status'] ?? null) === 'expired' => new ProviderSnapshot(subscriptions: [StripeMapper::expiredSubscriptionSession($session)]),
            ($session['mode'] ?? null) === 'payment' => new ProviderSnapshot(payments: [StripeMapper::sessionWithoutIntent($session)]),
            default => new ProviderSnapshot,
        };
    }

    /**
     * Un PaymentIntent puede ser de un pago único o del cobro de una factura
     * de suscripción; en ese caso se consulta la factura.
     */
    private function fetchPaymentIntent(string $id): ProviderSnapshot
    {
        $invoicePayments = $this->client->invoicePayments->all([
            'payment' => ['type' => 'payment_intent', 'payment_intent' => $id],
            'limit' => 1,
        ])->data;
        $invoice = isset($invoicePayments[0]) ? $this->array($invoicePayments[0])['invoice'] ?? null : null;
        if (is_string($invoice)) {
            return $this->fetchInvoice($invoice);
        }

        $intent = $this->array($this->client->paymentIntents->retrieve($id));

        return new ProviderSnapshot(payments: [StripeMapper::oneTimePayment(
            $intent,
            $this->list($this->client->charges->all(['payment_intent' => $id, 'limit' => 100])->data),
            $this->list($this->client->refunds->all(['payment_intent' => $id, 'limit' => 100])->data),
        )]);
    }

    private function fetchInvoice(string $id): ProviderSnapshot
    {
        $invoice = $this->array($this->client->invoices->retrieve($id, ['expand' => ['payments']]));
        $charges = [];
        $refunds = [];

        $payments = is_array($invoice['payments']['data'] ?? null) ? $invoice['payments']['data'] : [];
        foreach ($payments as $invoicePayment) {
            $intent = is_array($invoicePayment) ? ($invoicePayment['payment']['payment_intent'] ?? null) : null;
            if (is_string($intent)) {
                $charges = [...$charges, ...$this->list($this->client->charges->all(['payment_intent' => $intent, 'limit' => 100])->data)];
                $refunds = [...$refunds, ...$this->list($this->client->refunds->all(['payment_intent' => $intent, 'limit' => 100])->data)];
            }
        }

        usort($charges, fn (array $a, array $b): int => ($a['created'] ?? 0) <=> ($b['created'] ?? 0));
        $snapshot = StripeMapper::recurringPayment($invoice, $charges, $refunds);

        // Una factura sin suscripción (por ejemplo, creada a mano) no es de un donativo mensual.
        return $snapshot->subscriptionExternalId !== null ? new ProviderSnapshot(payments: [$snapshot]) : new ProviderSnapshot;
    }

    private function fetchStripeSubscription(string $id): ProviderSnapshot
    {
        return new ProviderSnapshot(subscriptions: [StripeMapper::subscription($this->array($this->client->subscriptions->retrieve($id)))]);
    }

    /**
     * Traduce los errores del SDK a errores del dominio con mensajes propios
     * (nunca el cuerpo de la respuesta).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     *
     * @throws PaymentProviderException
     */
    private function call(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (ApiConnectionException|RateLimitException $exception) {
            throw new ProviderUnavailableException(PaymentProvider::Stripe, 'Stripe no respondió a tiempo. Se reintentará.', $exception->getStripeCode(), $exception);
        } catch (CardException $exception) {
            throw new ProviderRejectedException(PaymentProvider::Stripe, 'Stripe rechazó la operación con la tarjeta.', $exception->getDeclineCode() ?? $exception->getStripeCode(),
                StripeMapper::failureCategory($exception->getDeclineCode() ?? $exception->getStripeCode()), $exception);
        } catch (ApiErrorException $exception) {
            if (($exception->getHttpStatus() ?? 500) >= 500) {
                throw new ProviderUnavailableException(PaymentProvider::Stripe, 'Stripe tuvo un error temporal. Se reintentará.', $exception->getStripeCode(), $exception);
            }

            throw new ProviderRejectedException(PaymentProvider::Stripe, 'Stripe rechazó la operación ('.($exception->getStripeCode() ?? 'sin código').').',
                $exception->getStripeCode(), FailureCategory::Unknown, $exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function array(StripeObject $object): array
    {
        /** @var array<string, mixed> $data */
        $data = $object->toArray();

        return $data;
    }

    /**
     * @param  array<int, StripeObject>  $objects
     * @return list<array<string, mixed>>
     */
    private function list(array $objects): array
    {
        return array_values(array_map($this->array(...), $objects));
    }
}
