<?php

declare(strict_types=1);

use App\Actions\Payments\StartOneTimeDonation;
use App\Actions\Refunds\RequestRefund;
use App\Actions\Subscriptions\PauseSubscription;
use App\Actions\Subscriptions\StartMonthlyDonation;
use App\Enums\DisputeStatus;
use App\Enums\FailureCategory;
use App\Enums\IncidentType;
use App\Enums\PaymentKind;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Enums\RetryOwner;
use App\Enums\Role;
use App\Enums\SubscriptionStatus;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentDispute;
use App\Models\PaymentIncident;
use App\Models\Subscription;
use App\Models\WebhookEvent;
use App\Payments\Exceptions\GatewayNotAvailableException;
use App\Payments\Exceptions\ProviderRejectedException;
use App\Payments\Exceptions\ProviderUnavailableException;
use App\Payments\GatewayRegistry;
use App\Payments\Gateways\MercadoPago\MercadoPagoMapper;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\call;

/*
 * Adaptador de Mercado Pago sin Internet (Http::fake). Datos y llaves ficticios.
 * Los campos marcados [S] en el adaptador se confirman en sandbox.
 */

const MP_TEST_WEBHOOK_SECRET = 'mp_secreto_ficticio';
const MP_BASE = 'https://api.mercadopago.com';

beforeEach(function (): void {
    config([
        'payments.providers.mercado_pago.enabled' => true,
        'payments.providers.mercado_pago.mode' => 'test',
        'payments.providers.mercado_pago.access_token' => 'TEST-token-ficticio',
        'payments.providers.mercado_pago.webhook_secret' => MP_TEST_WEBHOOK_SECRET,
    ]);
    app(GatewayRegistry::class)->flush();
    Http::preventStrayRequests();
});

/**
 * @param  array<string, mixed>  $body
 * @return TestResponse<Response>
 */
function mercadoPagoWebhook(string $type, string $dataId, array $body = [], ?string $secret = null): TestResponse
{
    $ts = (string) time();
    $requestId = 'req-'.Str::random(8);
    $manifest = 'id:'.strtolower($dataId).";request-id:{$requestId};ts:{$ts};";
    $signature = hash_hmac('sha256', $manifest, $secret ?? MP_TEST_WEBHOOK_SECRET);
    $payload = (string) json_encode(['id' => random_int(1000, 999999), 'type' => $type, 'action' => "{$type}.updated", 'live_mode' => false,
        'date_created' => now()->toIso8601String(), 'data' => ['id' => $dataId], ...$body], JSON_THROW_ON_ERROR);

    return call('POST', "/webhooks/payments/mercado_pago?data.id={$dataId}&type={$type}", [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_SIGNATURE' => "ts={$ts},v1={$signature}",
        'HTTP_X_REQUEST_ID' => $requestId,
    ], $payload);
}

it('no se habilita sin credenciales o con un modo inválido', function (array $config): void {
    config($config);
    app(GatewayRegistry::class)->flush();

    expect(fn () => app(GatewayRegistry::class)->get(PaymentProvider::MercadoPago))->toThrow(GatewayNotAvailableException::class);
})->with([
    'sin token' => [['payments.providers.mercado_pago.access_token' => '']],
    'sin secreto' => [['payments.providers.mercado_pago.webhook_secret' => null]],
    'modo inválido' => [['payments.providers.mercado_pago.mode' => 'sandbox']],
]);

it('no inventa límites técnicos que no se pudieron verificar', function (): void {
    $limits = app(GatewayRegistry::class)->get(PaymentProvider::MercadoPago)->amountLimits();

    expect($limits->min)->toBeNull()->and($limits->max)->toBeNull();
});

it('crea la Order con el token de la tarjeta, la referencia del CRM y la llave de idempotencia', function (): void {
    Http::fake([MP_BASE.'/v1/orders' => Http::response([
        'id' => 'ORD01', 'status' => 'processed', 'status_detail' => 'accredited', 'external_reference' => 'crm-payment-1',
        'total_amount' => '450.00', 'last_updated_date' => '2026-09-23T10:00:00.000-06:00',
        'transactions' => ['payments' => [['id' => 'PAY01', 'status' => 'processed', 'status_detail' => 'accredited', 'amount' => '450.00',
            'payment_method' => ['id' => 'visa', 'type' => 'credit_card']]]],
    ])]);
    $key = 'form-'.Str::uuid();

    $payment = app(StartOneTimeDonation::class)->handle([
        'provider' => 'mercado_pago', 'donor_id' => Donor::factory()->create()->id, 'amount' => '450', 'idempotency_key' => $key,
        'card_token' => 'tok_ficticio', 'payment_method_id' => 'visa',
    ])['payment'];

    Http::assertSent(fn (HttpRequest $request): bool => $request->url() === MP_BASE.'/v1/orders'
        && $request->header('X-Idempotency-Key')[0] === $key
        && $request->hasHeader('Authorization', 'Bearer TEST-token-ficticio')
        && $request['external_reference'] === "crm-payment-{$payment->id}"
        && $request['transactions']['payments'][0]['payment_method']['token'] === 'tok_ficticio'
        && $request['total_amount'] === '450.00');

    expect($payment->status)->toBe(PaymentStatus::Succeeded)
        ->and($payment->external_id)->toBe('ORD01')
        ->and(PaymentAttempt::query()->sole()->external_id)->toBe('PAY01')
        ->and(Donation::query()->count())->toBe(1);
});

it('un rechazo de tarjeta queda como intento fallido con su categoría', function (): void {
    Http::fake([MP_BASE.'/v1/orders' => Http::response([
        'id' => 'ORD02', 'status' => 'failed', 'external_reference' => 'crm-payment-x', 'total_amount' => '100.00',
        'transactions' => ['payments' => [['id' => 'PAY02', 'status' => 'failed', 'status_detail' => 'cc_rejected_insufficient_amount', 'amount' => '100.00']]],
    ])]);

    $payment = app(StartOneTimeDonation::class)->handle([
        'provider' => 'mercado_pago', 'donor_id' => Donor::factory()->create()->id, 'amount' => '100', 'idempotency_key' => 'form-'.Str::uuid(),
        'card_token' => 'tok', 'payment_method_id' => 'visa',
    ])['payment'];

    expect($payment->status)->toBe(PaymentStatus::Failed)
        ->and(PaymentAttempt::query()->sole()->failure_category)->toBe(FailureCategory::InsufficientFunds)
        ->and(Donation::query()->count())->toBe(0)
        ->and(PaymentIncident::query()->count())->toBe(0);
});

it('inicia la suscripción sin plan (/preapproval) mensual en MXN con el token de la tarjeta', function (): void {
    Http::fake([MP_BASE.'/preapproval' => Http::response([
        'id' => 'PRE01', 'status' => 'authorized', 'external_reference' => 'crm-subscription-1',
        'auto_recurring' => ['frequency' => 1, 'frequency_type' => 'months', 'transaction_amount' => 300, 'currency_id' => 'MXN'],
        'next_payment_date' => '2026-10-23T10:00:00.000-06:00', 'date_created' => '2026-09-23T10:00:00.000-06:00', 'last_modified' => '2026-09-23T10:00:00.000-06:00',
    ])]);

    $subscription = app(StartMonthlyDonation::class)->handle([
        'provider' => 'mercado_pago', 'donor_id' => Donor::factory()->create()->id, 'amount' => '300', 'idempotency_key' => 'form-'.Str::uuid(), 'card_token' => 'tok',
    ])['subscription'];

    Http::assertSent(fn (HttpRequest $request): bool => $request->url() === MP_BASE.'/preapproval'
        && $request['auto_recurring']['frequency'] === 1 && $request['auto_recurring']['frequency_type'] === 'months'
        && $request['auto_recurring']['currency_id'] === 'MXN' && $request['status'] === 'authorized'
        && $request['card_token_id'] === 'tok');

    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->external_id)->toBe('PRE01')
        ->and($subscription->retry_owner)->toBe(RetryOwner::Provider);
});

it('rechaza notificaciones con firma inválida', function (): void {
    mercadoPagoWebhook('payment', '123', [], 'otro_secreto')->assertStatus(400);
    call('POST', '/webhooks/payments/mercado_pago?data.id=1', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')->assertStatus(400);

    expect(WebhookEvent::query()->count())->toBe(0);
});

it('cuota en recycling: intento fallido, reintento a cargo del proveedor, alerta temprana y suscripción activa', function (): void {
    $subscription = Subscription::factory()->create(['provider' => PaymentProvider::MercadoPago, 'external_id' => 'PRE02', 'amount' => '300.00']);
    Http::fake([MP_BASE.'/authorized_payments/7001' => Http::response([
        'id' => 7001, 'preapproval_id' => 'PRE02', 'status' => 'recycling', 'transaction_amount' => 300, 'currency_id' => 'MXN',
        'debit_date' => '2026-10-01T10:00:00.000-06:00', 'next_retry_date' => '2026-10-03T10:00:00.000-06:00', 'retry_attempt' => 1,
        'last_modified' => '2026-10-01T10:05:00.000-06:00',
        'payment' => ['id' => 9001, 'status' => 'rejected', 'status_detail' => 'cc_rejected_insufficient_amount'],
    ])]);

    mercadoPagoWebhook('subscription_authorized_payment', '7001')->assertOk();

    $payment = Payment::query()->where('external_id', '7001')->sole();
    expect($payment->kind)->toBe(PaymentKind::RecurringCharge)
        ->and($payment->subscription_id)->toBe($subscription->id)
        ->and($payment->billing_period_start?->toDateString())->toBe('2026-10-01')
        ->and($payment->status)->toBe(PaymentStatus::Pending)
        ->and($payment->next_retry_owner)->toBe(RetryOwner::Provider)
        ->and(PaymentAttempt::query()->sole()->failure_category)->toBe(FailureCategory::InsufficientFunds)
        ->and(PaymentIncident::query()->sole()->type)->toBe(IncidentType::RecurringAttemptFailed)
        ->and($subscription->fresh()?->status)->toBe(SubscriptionStatus::Active)
        ->and(Donation::query()->count())->toBe(0);
});

it('pausa con PUT /preapproval y solo la refleja cuando Mercado Pago la confirma', function (): void {
    $subscription = Subscription::factory()->create(['provider' => PaymentProvider::MercadoPago, 'external_id' => 'PRE03']);
    Http::fake([MP_BASE.'/preapproval/PRE03' => Http::response(['id' => 'PRE03', 'status' => 'paused'])]);

    app(PauseSubscription::class)->handle($subscription, 'Pausa solicitada por el donante', userWithRole(Role::FundraisingCoordinator));

    Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'PUT' && $request['status'] === 'paused');
    expect($subscription->fresh()?->status)->toBe(SubscriptionStatus::Paused);
});

it('reembolsa sobre la Order con la llave de idempotencia y sin enviar motivos del CRM', function (): void {
    $payment = Payment::factory()->succeeded()->create(['provider' => PaymentProvider::MercadoPago, 'external_id' => 'ORD05', 'amount' => '500.00']);
    PaymentAttempt::query()->create(['payment_id' => $payment->id, 'provider' => PaymentProvider::MercadoPago, 'external_id' => 'PAY05',
        'attempt_number' => 1, 'status' => 'succeeded', 'initiated_by' => 'donor']);
    Http::fake([MP_BASE.'/v1/orders/ORD05/refund' => Http::response([
        'id' => 'ORD05', 'status' => 'processed', 'total_amount' => '500.00',
        'transactions' => ['payments' => [['id' => 'PAY05', 'status' => 'processed']], 'refunds' => [['id' => 'REF05', 'transaction_id' => 'PAY05', 'status' => 'processed', 'amount' => '120.00']]],
    ])]);
    $key = 'refund-'.Str::uuid();

    $refund = app(RequestRefund::class)->handle($payment, ['amount' => '120', 'reason' => 'donor_request', 'idempotency_key' => $key], userWithRole(Role::Accountant));

    Http::assertSent(fn (HttpRequest $request): bool => $request->header('X-Idempotency-Key')[0] === $key
        && $request['transactions'][0]['id'] === 'PAY05' && $request['transactions'][0]['amount'] === '120.00'
        && ! isset($request['reason']));
    expect($refund->status)->toBe(RefundStatus::Succeeded)
        ->and($refund->external_id)->toBe('REF05')
        ->and($refund->provider_reason)->toBeNull();
});

it('un contracargo abre una disputa ligada al pago por su intento', function (): void {
    $payment = Payment::factory()->succeeded()->create(['provider' => PaymentProvider::MercadoPago, 'external_id' => 'ORD06']);
    PaymentAttempt::query()->create(['payment_id' => $payment->id, 'provider' => PaymentProvider::MercadoPago, 'external_id' => '555',
        'attempt_number' => 1, 'status' => 'succeeded', 'initiated_by' => 'donor']);
    Http::fake([MP_BASE.'/v1/chargebacks/CB1' => Http::response([
        'id' => 'CB1', 'payments' => [555], 'amount' => 500, 'currency' => 'MXN', 'documentation_required' => true,
        'documentation_status' => 'pending', 'coverage_applied' => null, 'date_documentation_deadline' => '2026-10-10T00:00:00.000-06:00',
        'date_created' => '2026-09-23T10:00:00.000-06:00',
    ])]);

    mercadoPagoWebhook('topic_chargebacks_wh', 'CB1')->assertOk();

    expect(PaymentDispute::query()->sole()->payment_id)->toBe($payment->id)
        ->and(PaymentDispute::query()->sole()->status)->toBe(DisputeStatus::Open)
        ->and(PaymentIncident::query()->sole()->type)->toBe(IncidentType::DisputeOpened);
});

it('guarda solo la lista permitida de la notificación', function (): void {
    Http::fake([MP_BASE.'/v1/payments/888' => Http::response(['id' => 888, 'status' => 'approved'])]);

    mercadoPagoWebhook('payment', '888', ['payer' => ['email' => 'donante@example.com'], 'token' => 'tok_secreto'])->assertOk();

    $json = json_encode(WebhookEvent::query()->sole()->payload, JSON_THROW_ON_ERROR);
    expect($json)->toContain('888')->and($json)->not->toContain('donante@example.com')->and($json)->not->toContain('tok_secreto');
});

it('un error 5xx o de red se trata como no disponible y un 4xx como rechazo, sin copiar la respuesta', function (): void {
    Http::fake([MP_BASE.'/v1/orders/X1' => Http::response(['message' => 'detalle interno con 4242424242424242'], 500)]);
    $gateway = app(GatewayRegistry::class)->get(PaymentProvider::MercadoPago);

    expect(fn () => $gateway->fetch('order', 'X1'))->toThrow(ProviderUnavailableException::class);

    Http::fake([MP_BASE.'/v1/orders/X2' => Http::response(['code' => 'invalid_order', 'message' => 'no'], 400)]);
    try {
        $gateway->fetch('order', 'X2');
    } catch (ProviderRejectedException $exception) {
        expect($exception->getMessage())->toBe('Mercado Pago rechazó la operación (invalid_order).');
    }
});

it('mapea los rechazos y las cuotas procesadas', function (): void {
    expect(MercadoPagoMapper::failureCategory('cc_rejected_bad_filled_security_code'))->toBe(FailureCategory::InvalidPaymentData)
        ->and(MercadoPagoMapper::failureCategory('cc_rejected_high_risk'))->toBe(FailureCategory::SuspectedFraud)
        ->and(MercadoPagoMapper::failureCategory('cc_rejected_duplicated_payment'))->toBe(FailureCategory::Duplicate)
        ->and(MercadoPagoMapper::failureCategory('cc_rejected_other_reason'))->toBe(FailureCategory::Declined)
        ->and(MercadoPagoMapper::authorizedPayment(['id' => 1, 'status' => 'processed', 'payment' => ['id' => 2, 'status' => 'approved']])->status)->toBe(PaymentStatus::Succeeded)
        ->and(MercadoPagoMapper::authorizedPayment(['id' => 1, 'status' => 'processed', 'payment' => ['id' => 2, 'status' => 'rejected']])->status)->toBe(PaymentStatus::Failed)
        ->and(MercadoPagoMapper::preapproval(['id' => 'P', 'status' => 'cancelled'])->status)->toBe(SubscriptionStatus::Cancelled)
        ->and(MercadoPagoMapper::amount(300.5))->toBe('300.50');
});
