<?php

declare(strict_types=1);

namespace App\Payments\Gateways\MercadoPago;

use App\Enums\FailureCategory;
use App\Enums\PaymentKind;
use App\Enums\PaymentProvider;
use App\Enums\RefundReason;
use App\Enums\RefundStatus;
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
use App\Support\Money;
use App\Support\SensitiveData;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Adaptador de Mercado Pago con el cliente HTTP de Laravel (ADR-011: sin
 * SDK). Opción A aprobada: pago único con la API de Orders y donativo
 * mensual con Suscripciones `/preapproval` sin plan; Mercado Pago reintenta
 * las cuotas ("recycling"), el CRM solo sincroniza.
 *
 * Todo lo marcado [S] se confirma en sandbox (checklist §16.3) antes de
 * cerrar el adaptador. La tarjeta se tokeniza en el navegador con los
 * componentes oficiales: el CRM solo recibe el token.
 */
final class MercadoPagoGateway implements PausesSubscriptions, PaymentGateway, ProcessesOneTimePayments, ProcessesRecurringPayments, ProcessesRefunds
{
    private readonly string $accessToken;

    private readonly string $webhookSecret;

    private readonly bool $testMode;

    /**
     * @throws GatewayNotAvailableException
     */
    public function __construct()
    {
        $token = config('payments.providers.mercado_pago.access_token');
        $secret = config('payments.providers.mercado_pago.webhook_secret');
        $mode = config('payments.providers.mercado_pago.mode');

        if (! is_string($token) || $token === '' || ! is_string($secret) || $secret === '') {
            throw new GatewayNotAvailableException('Mercado Pago no está configurado (faltan MERCADO_PAGO_ACCESS_TOKEN o MERCADO_PAGO_WEBHOOK_SECRET).');
        }

        if (! in_array($mode, ['test', 'live'], true)) {
            throw new GatewayNotAvailableException('MERCADO_PAGO_MODE debe ser "test" o "live".');
        }

        $this->accessToken = $token;
        $this->webhookSecret = $secret;
        $this->testMode = $mode === 'test';
    }

    public function provider(): PaymentProvider
    {
        return PaymentProvider::MercadoPago;
    }

    public function isTestMode(): bool
    {
        return $this->testMode;
    }

    /**
     * [S] Los límites de Mercado Pago México con tarjeta no se pudieron
     * verificar en su documentación: no se aplica ninguno inventado.
     */
    public function amountLimits(): AmountLimits
    {
        return new AmountLimits(null, null, 'Mercado Pago: límites por confirmar en sandbox');
    }

    /**
     * [V] Firma: encabezado x-signature ("ts=…,v1=…") y x-request-id; se
     * calcula HMAC-SHA256 del manifiesto "id:<data.id>;request-id:<x-request-id>;ts:<ts>;"
     * con la clave secreta del webhook. data.id se toma de la URL y va en
     * minúsculas si es alfanumérico.
     */
    public function parseWebhook(Request $request): InboundWebhook
    {
        $parts = [];
        foreach (explode(',', (string) $request->header('x-signature', '')) as $piece) {
            [$key, $value] = array_pad(explode('=', trim($piece), 2), 2, '');
            $parts[$key] = $value;
        }

        $ts = $parts['ts'] ?? '';
        $v1 = $parts['v1'] ?? '';
        $requestId = (string) $request->header('x-request-id', '');
        $dataId = self::queryDataId($request);

        if ($ts === '' || $v1 === '') {
            throw new InvalidWebhookSignatureException('Notificación de Mercado Pago sin firma.');
        }

        $manifest = ($dataId !== null ? 'id:'.(ctype_alnum($dataId) ? strtolower($dataId) : $dataId).';' : '')
            .($requestId !== '' ? "request-id:{$requestId};" : '')
            ."ts:{$ts};";

        if (! hash_equals(hash_hmac('sha256', $manifest, $this->webhookSecret), $v1)) {
            throw new InvalidWebhookSignatureException('Firma de Mercado Pago inválida.');
        }

        /** @var array<string, mixed> $body */
        $body = json_decode($request->getContent() !== '' ? $request->getContent() : '{}', true, 32, JSON_THROW_ON_ERROR);
        $type = (string) ($body['type'] ?? $body['topic'] ?? 'unknown');
        $resourceId = $dataId ?? (is_array($body['data'] ?? null) && isset($body['data']['id']) ? (string) $body['data']['id'] : null);
        $route = MercadoPagoMapper::route($type, $resourceId);

        return new InboundWebhook(
            // [S] Se usa el id de la notificación; si no viene, una combinación estable.
            externalEventId: isset($body['id']) ? (string) $body['id'] : "{$type}:{$resourceId}:{$requestId}",
            eventType: (string) ($body['action'] ?? $type),
            resourceType: $route[0] ?? null,
            resourceExternalId: $route[1] ?? null,
            payload: SensitiveData::allow([...$body, 'headers' => ['x-request-id' => $requestId, 'ts' => $ts]], MercadoPagoMapper::WEBHOOK_ALLOWLIST),
            providerCreatedAt: is_string($body['date_created'] ?? null) ? CarbonImmutable::parse($body['date_created']) : null,
        );
    }

    public function fetch(string $resourceType, string $externalId): ProviderSnapshot
    {
        $id = rawurlencode($externalId);

        return match ($resourceType) {
            'order' => new ProviderSnapshot(payments: [MercadoPagoMapper::order($this->get("/v1/orders/{$id}"))]),
            'payment' => $this->fetchPaymentResource($id),
            'preapproval' => new ProviderSnapshot(subscriptions: [MercadoPagoMapper::preapproval($this->get("/preapproval/{$id}"))]),
            'authorized_payment' => new ProviderSnapshot(payments: [MercadoPagoMapper::authorizedPayment($this->get("/authorized_payments/{$id}"))]),
            'chargeback' => new ProviderSnapshot(disputes: [MercadoPagoMapper::chargeback($this->get("/v1/chargebacks/{$id}"))]),
            default => new ProviderSnapshot,
        };
    }

    public function fetchPayment(Payment $payment): ProviderSnapshot
    {
        if ($payment->external_id === null) {
            return new ProviderSnapshot;
        }

        return $this->fetch($payment->kind === PaymentKind::RecurringCharge ? 'authorized_payment' : 'order', $payment->external_id);
    }

    public function fetchSubscription(Subscription $subscription): ProviderSnapshot
    {
        return $subscription->external_id !== null ? $this->fetch('preapproval', $subscription->external_id) : new ProviderSnapshot;
    }

    public function fetchRefund(Refund $refund): ProviderSnapshot
    {
        $payment = $refund->payment;

        return $payment->external_id !== null ? $this->fetch('order', $payment->external_id) : new ProviderSnapshot;
    }

    /**
     * Crea y procesa la Order con el token de la tarjeta (Card Payment
     * Brick). [S] Campos exactos de la API de Orders.
     */
    public function startOneTimePayment(OneTimePaymentRequest $request): CheckoutResult
    {
        if ($request->cardToken === null || $request->paymentMethodId === null) {
            throw new ProviderRejectedException(PaymentProvider::MercadoPago, 'Falta el token de la tarjeta de Mercado Pago.', 'missing_card_token', FailureCategory::InvalidPaymentData);
        }

        $order = $this->post('/v1/orders', [
            'type' => 'online',
            'processing_mode' => 'automatic',
            'total_amount' => $request->amount,
            'external_reference' => MercadoPagoMapper::PAYMENT_REFERENCE.$request->paymentId,
            'description' => $request->description,
            ...($request->donorEmail !== null ? ['payer' => ['email' => $request->donorEmail]] : []),
            'transactions' => ['payments' => [[
                'amount' => $request->amount,
                'payment_method' => [
                    'id' => $request->paymentMethodId,
                    'type' => 'credit_card',
                    'token' => $request->cardToken,
                    'installments' => 1,
                ],
            ]]],
        ], $request->idempotencyKey);

        $snapshot = MercadoPagoMapper::order($order);

        return new CheckoutResult(paymentExternalId: $snapshot->externalId, snapshot: new ProviderSnapshot(payments: [$snapshot]));
    }

    public function supportedIntervals(): array
    {
        return [SubscriptionInterval::Monthly];
    }

    public function retryOwner(): RetryOwner
    {
        return RetryOwner::Provider;
    }

    /**
     * [V] Suscripción sin plan con pago autorizado: POST /preapproval con
     * card_token_id, auto_recurring y status "authorized".
     */
    public function startSubscription(SubscriptionRequest $request): CheckoutResult
    {
        if ($request->cardToken === null) {
            throw new ProviderRejectedException(PaymentProvider::MercadoPago, 'Falta el token de la tarjeta de Mercado Pago.', 'missing_card_token', FailureCategory::InvalidPaymentData);
        }

        $preapproval = $this->post('/preapproval', [
            'reason' => $request->description,
            'external_reference' => MercadoPagoMapper::SUBSCRIPTION_REFERENCE.$request->subscriptionId,
            ...($request->donorEmail !== null ? ['payer_email' => $request->donorEmail] : []),
            'card_token_id' => $request->cardToken,
            'auto_recurring' => [
                'frequency' => 1,
                'frequency_type' => 'months',
                'transaction_amount' => (float) $request->amount,
                'currency_id' => 'MXN',
            ],
            'back_url' => $request->returnUrl,
            'status' => 'authorized',
        ], $request->idempotencyKey);

        $snapshot = MercadoPagoMapper::preapproval($preapproval);

        return new CheckoutResult(subscriptionExternalId: $snapshot->externalId, snapshot: new ProviderSnapshot(subscriptions: [$snapshot]));
    }

    public function cancelSubscription(string $externalId): SubscriptionSnapshot
    {
        return $this->changePreapproval($externalId, 'cancelled');
    }

    public function pauseSubscription(string $externalId): SubscriptionSnapshot
    {
        return $this->changePreapproval($externalId, 'paused');
    }

    /**
     * [S] Reactivación: se usa status "authorized"; confirmar en sandbox.
     */
    public function resumeSubscription(string $externalId): SubscriptionSnapshot
    {
        return $this->changePreapproval($externalId, 'authorized');
    }

    /**
     * [S] La API de reembolsos no recibe un motivo: nunca se envía texto del CRM.
     */
    public function providerRefundReason(RefundReason $reason): ?string
    {
        return null;
    }

    /**
     * [S] Reembolso parcial o total de una Order: POST /v1/orders/{id}/refund.
     */
    public function refund(RefundRequest $request): RefundSnapshot
    {
        $orderId = rawurlencode((string) $request->paymentExternalId);
        $order = $this->post("/v1/orders/{$orderId}/refund", [
            'transactions' => [[
                'id' => $request->attemptExternalId,
                'amount' => $request->amount,
            ]],
        ], $request->idempotencyKey);

        $refunds = MercadoPagoMapper::order($order)->refunds;
        $matching = array_values(array_filter($refunds, fn (RefundSnapshot $refund): bool => Money::compare($refund->amount, $request->amount) === 0));
        $refund = end($matching) ?: end($refunds);

        return $refund !== false ? $refund : new RefundSnapshot(
            externalId: "{$request->paymentExternalId}:{$request->idempotencyKey}",
            status: RefundStatus::Pending,
            amount: $request->amount,
            paymentExternalId: $request->paymentExternalId,
        );
    }

    private function fetchPaymentResource(string $id): ProviderSnapshot
    {
        $payment = $this->get("/v1/payments/{$id}");
        $order = is_array($payment['order'] ?? null) && isset($payment['order']['id']) ? (string) $payment['order']['id'] : null;

        // Los pagos de suscripciones se siguen con su authorized_payment; los ajenos al CRM se ignoran.
        return $order !== null ? $this->fetch('order', $order) : new ProviderSnapshot;
    }

    private function changePreapproval(string $externalId, string $status): SubscriptionSnapshot
    {
        $id = rawurlencode($externalId);

        return MercadoPagoMapper::preapproval($this->send(fn (PendingRequest $http): Response => $http->put("/preapproval/{$id}", ['status' => $status])));
    }

    /**
     * @return array<string, mixed>
     *
     * @throws PaymentProviderException
     */
    private function get(string $path): array
    {
        return $this->send(fn (PendingRequest $http): Response => $http->get($path));
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     *
     * @throws PaymentProviderException
     */
    private function post(string $path, array $body, string $idempotencyKey): array
    {
        // [V] X-Idempotency-Key es obligatorio en pagos y reembolsos.
        return $this->send(fn (PendingRequest $http): Response => $http->withHeaders(['X-Idempotency-Key' => $idempotencyKey])->post($path, $body));
    }

    /**
     * @param  callable(PendingRequest): Response  $callback
     * @return array<string, mixed>
     *
     * @throws PaymentProviderException
     */
    private function send(callable $callback): array
    {
        $http = Http::baseUrl(config()->string('payments.providers.mercado_pago.base_url'))
            ->withToken($this->accessToken)
            ->acceptJson()
            ->asJson()
            ->timeout(config()->integer('payments.providers.mercado_pago.timeout'));

        try {
            $response = $callback($http);
        } catch (ConnectionException $exception) {
            throw new ProviderUnavailableException(PaymentProvider::MercadoPago, 'Mercado Pago no respondió a tiempo. Se reintentará.', null, $exception);
        }

        if ($response->serverError() || $response->status() === 429) {
            throw new ProviderUnavailableException(PaymentProvider::MercadoPago, 'Mercado Pago tuvo un error temporal. Se reintentará.', (string) $response->status());
        }

        if ($response->failed()) {
            $code = $response->json('code') ?? $response->json('error') ?? (string) $response->status();
            $code = is_scalar($code) ? mb_substr((string) $code, 0, 100) : (string) $response->status();

            throw new ProviderRejectedException(PaymentProvider::MercadoPago, "Mercado Pago rechazó la operación ({$code}).", $code);
        }

        $data = $response->json();

        /** @var array<string, mixed> $result */
        $result = is_array($data) ? $data : [];

        return $result;
    }

    /**
     * `data.id` de la URL de la notificación (PHP convierte el punto en guion
     * bajo al leer la consulta, así que se lee de la cadena original).
     */
    private static function queryDataId(Request $request): ?string
    {
        $query = $request->server('QUERY_STRING');

        foreach (explode('&', is_string($query) ? $query : '') as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            if (urldecode($key) === 'data.id' && $value !== '') {
                return urldecode($value);
            }
        }

        return null;
    }
}
