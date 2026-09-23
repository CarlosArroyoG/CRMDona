<?php

declare(strict_types=1);

use App\Actions\Payments\StartOneTimeDonation;
use App\Actions\Refunds\RequestRefund;
use App\Enums\DisputeStatus;
use App\Enums\FailureCategory;
use App\Enums\PaymentKind;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Enums\RefundReason;
use App\Enums\RefundStatus;
use App\Enums\RetryOwner;
use App\Enums\Role;
use App\Enums\SubscriptionStatus;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\Refund;
use App\Models\Subscription;
use App\Models\WebhookEvent;
use App\Payments\Exceptions\GatewayNotAvailableException;
use App\Payments\Exceptions\ProviderUnavailableException;
use App\Payments\GatewayRegistry;
use App\Payments\Gateways\Stripe\StripeGateway;
use App\Payments\Gateways\Stripe\StripeMapper;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Stripe\ApiRequestor;
use Stripe\HttpClient\CurlClient;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakeStripeHttpClient;

use function Pest\Laravel\call;

/*
 * Adaptador de Stripe sin Internet: el SDK oficial habla con un cliente HTTP
 * falso. Los datos son ficticios; ninguna llave es real.
 */

const STRIPE_TEST_WEBHOOK_SECRET = 'whsec_prueba_ficticia';

beforeEach(function (): void {
    config([
        'payments.providers.stripe.enabled' => true,
        'payments.providers.stripe.mode' => 'test',
        'payments.providers.stripe.secret_key' => 'sk_test_ficticia_para_pruebas',
        'payments.providers.stripe.webhook_secret' => STRIPE_TEST_WEBHOOK_SECRET,
    ]);
    app(GatewayRegistry::class)->flush();
    app()->instance(FakeStripeHttpClient::class, new FakeStripeHttpClient);
    ApiRequestor::setHttpClient(stripeHttp());
});

afterEach(function (): void {
    ApiRequestor::setHttpClient(CurlClient::instance());
});

function stripeHttp(): FakeStripeHttpClient
{
    return app(FakeStripeHttpClient::class);
}

/**
 * @param  array<string, mixed>  $object
 * @return TestResponse<Response>
 */
function stripeWebhook(string $type, array $object, string $eventId = 'evt_1'): TestResponse
{
    $payload = (string) json_encode(['id' => $eventId, 'object' => 'event', 'type' => $type, 'created' => time(),
        'livemode' => false, 'api_version' => '2026-08-26.dahlia', 'data' => ['object' => $object]], JSON_THROW_ON_ERROR);
    $timestamp = time();
    $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", STRIPE_TEST_WEBHOOK_SECRET);

    return call('POST', '/webhooks/payments/stripe', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
    ], $payload);
}

/**
 * @return array<string, mixed>
 */
function stripeCharge(string $id, string $status, ?string $declineCode = null, int $created = 1_790_000_000): array
{
    return [
        'id' => $id, 'object' => 'charge', 'status' => $status, 'created' => $created, 'amount' => 50000,
        'failure_code' => $declineCode !== null ? 'card_declined' : null,
        'failure_message' => $declineCode !== null ? 'Your card was declined. 4242424242424242' : null,
        'outcome' => ['type' => $status === 'succeeded' ? 'authorized' : 'issuer_declined', 'reason' => $declineCode],
        'payment_method_details' => ['type' => 'card', 'card' => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 12, 'exp_year' => 2030, 'fingerprint' => 'fp_secreto']],
    ];
}

it('no se habilita si falta configuración o si el modo no coincide con la llave', function (array $config): void {
    config($config);
    app(GatewayRegistry::class)->flush();

    expect(fn () => app(GatewayRegistry::class)->get(PaymentProvider::Stripe))->toThrow(GatewayNotAvailableException::class);
})->with([
    'sin llave' => [['payments.providers.stripe.secret_key' => null]],
    'sin secreto de webhook' => [['payments.providers.stripe.webhook_secret' => '']],
    'modo prueba con llave de producción' => [['payments.providers.stripe.secret_key' => 'sk_live_ficticia']],
    'modo producción con llave de prueba' => [['payments.providers.stripe.mode' => 'live']],
    'modo desconocido' => [['payments.providers.stripe.mode' => 'demo']],
]);

it('inicia el pago único con Checkout embebido, solo tarjeta, en centavos y con la llave de idempotencia', function (): void {
    stripeHttp()->respond('POST', '/v1/checkout/sessions', ['id' => 'cs_test_1', 'object' => 'checkout.session', 'client_secret' => 'cs_test_1_secret', 'payment_intent' => null]);
    $key = 'form-'.Str::uuid();

    $result = app(StartOneTimeDonation::class)->handle([
        'provider' => 'stripe', 'donor_id' => Donor::factory()->create(['email' => 'donante@example.com'])->id, 'amount' => '1250.50', 'idempotency_key' => $key,
    ]);

    $request = stripeHttp()->requestsTo('POST', '/v1/checkout/sessions')[0];
    expect(FakeStripeHttpClient::header($request, 'Idempotency-Key'))->toBe($key)
        ->and($request['params']['ui_mode'])->toBe('embedded_page')
        ->and($request['params']['payment_method_types'])->toBe(['card'])
        ->and($request['params']['line_items'][0]['price_data']['unit_amount'])->toBe(125050)
        ->and($request['params']['line_items'][0]['price_data']['currency'])->toBe('mxn')
        ->and($request['params']['metadata']['crm_payment_id'])->toBe((string) $result['payment']->id)
        ->and($request['params']['payment_intent_data']['metadata']['crm_payment_id'])->toBe((string) $result['payment']->id)
        ->and($result['checkout']->clientSecret)->toBe('cs_test_1_secret')
        ->and($result['payment']->status)->toBe(PaymentStatus::Pending);
});

it('rechaza importes por debajo del mínimo técnico de Stripe (10 MXN)', function (): void {
    expect(fn () => app(StartOneTimeDonation::class)->handle([
        'provider' => 'stripe', 'donor_id' => Donor::factory()->create()->id, 'amount' => '9.99', 'idempotency_key' => 'form-'.Str::uuid(),
    ]))->toThrow(ValidationException::class, '$10.00');
});

it('rechaza webhooks con firma inválida', function (): void {
    call('POST', '/webhooks/payments/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => 't=1,v1=falsa'], '{"id":"evt_x"}')
        ->assertStatus(400);

    expect(WebhookEvent::query()->count())->toBe(0);
});

it('pago único: el webhook consulta el PaymentIntent y sus cargos, crea intentos y el donativo', function (): void {
    $payment = Payment::factory()->create(['provider' => PaymentProvider::Stripe, 'external_id' => null, 'amount' => '500.00']);
    stripeHttp()
        ->respond('GET', '/v1/invoice_payments', ['object' => 'list', 'data' => []])
        ->respond('GET', '/v1/payment_intents/pi_1', ['id' => 'pi_1', 'object' => 'payment_intent', 'status' => 'succeeded', 'amount' => 50000,
            'metadata' => ['crm_payment_id' => (string) $payment->id]])
        ->respond('GET', '/v1/charges', ['object' => 'list', 'data' => [
            stripeCharge('ch_1', 'failed', 'insufficient_funds', 1_790_000_000),
            stripeCharge('ch_2', 'succeeded', null, 1_790_000_100),
        ]])
        ->respond('GET', '/v1/refunds', ['object' => 'list', 'data' => []]);

    stripeWebhook('payment_intent.succeeded', ['id' => 'pi_1', 'object' => 'payment_intent', 'status' => 'succeeded',
        'client_secret' => 'pi_1_secret_no_guardar', 'metadata' => ['crm_payment_id' => (string) $payment->id]])->assertOk();

    $payment->refresh();
    $attempts = PaymentAttempt::query()->where('payment_id', $payment->id)->orderBy('attempt_number')->get()->all();
    expect($payment->status)->toBe(PaymentStatus::Succeeded)
        ->and($payment->external_id)->toBe('pi_1')
        ->and($attempts)->toHaveCount(2)
        ->and($attempts[0]->failure_category)->toBe(FailureCategory::InsufficientFunds)
        ->and($attempts[0]->provider_code)->toBe('insufficient_funds')
        ->and($attempts[0]->provider_message)->not->toContain('4242424242424242')
        ->and($attempts[1]->card_last4)->toBe('4242')
        ->and(Donation::query()->where('payment_id', $payment->id)->count())->toBe(1);

    $event = WebhookEvent::query()->sole();
    expect($event->resource_type)->toBe('payment_intent')
        ->and(json_encode($event->payload, JSON_THROW_ON_ERROR))->not->toContain('secret_no_guardar');
});

it('mensualidad: la factura es el pago del periodo y los reintentos de Smart Retries son intentos', function (): void {
    $subscription = Subscription::factory()->create(['provider' => PaymentProvider::Stripe, 'external_id' => 'sub_1', 'amount' => '300.00']);
    $invoice = [
        'id' => 'in_1', 'object' => 'invoice', 'status' => 'open', 'amount_due' => 30000, 'attempt_count' => 1,
        'next_payment_attempt' => 1_790_300_000, 'billing_reason' => 'subscription_cycle',
        'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => 'sub_1', 'metadata' => ['crm_subscription_id' => (string) $subscription->id]]],
        'lines' => ['object' => 'list', 'data' => [['period' => ['start' => 1_790_000_000, 'end' => 1_792_600_000]]]],
        'payments' => ['object' => 'list', 'data' => [['payment' => ['type' => 'payment_intent', 'payment_intent' => 'pi_inv']]]],
    ];
    stripeHttp()
        ->respond('GET', '/v1/invoices/in_1', $invoice)
        ->respond('GET', '/v1/charges', ['object' => 'list', 'data' => [stripeCharge('ch_a', 'failed', 'expired_card')]])
        ->respond('GET', '/v1/refunds', ['object' => 'list', 'data' => []]);

    stripeWebhook('invoice.payment_failed', ['id' => 'in_1', 'object' => 'invoice'])->assertOk();

    $payment = Payment::query()->where('external_id', 'in_1')->sole();
    expect($payment->kind)->toBe(PaymentKind::RecurringCharge)
        ->and($payment->subscription_id)->toBe($subscription->id)
        ->and($payment->status)->toBe(PaymentStatus::Pending)
        ->and($payment->next_retry_owner)->toBe(RetryOwner::Provider)
        ->and(Donation::query()->count())->toBe(0);
});

it('mapea los estados de suscripción, incluida la pausa de cobro', function (array $subscription, SubscriptionStatus $expected): void {
    expect(StripeMapper::subscription(['id' => 'sub_1', ...$subscription])->status)->toBe($expected);
})->with([
    'activa' => [['status' => 'active'], SubscriptionStatus::Active],
    'activa con pausa de cobro' => [['status' => 'active', 'pause_collection' => ['behavior' => 'void']], SubscriptionStatus::Paused],
    'vencida' => [['status' => 'past_due'], SubscriptionStatus::PastDue],
    'impagada' => [['status' => 'unpaid'], SubscriptionStatus::PastDue],
    'incompleta' => [['status' => 'incomplete'], SubscriptionStatus::Pending],
    'incompleta vencida' => [['status' => 'incomplete_expired'], SubscriptionStatus::Expired],
    'cancelada' => [['status' => 'canceled'], SubscriptionStatus::Cancelled],
]);

it('una factura abierta sin siguiente reintento queda fallida (Smart Retries agotado)', function (): void {
    $snapshot = StripeMapper::recurringPayment(['id' => 'in_2', 'status' => 'open', 'attempt_count' => 8, 'next_payment_attempt' => null], [], []);

    expect($snapshot->status)->toBe(PaymentStatus::Failed)->and($snapshot->nextRetryOwner)->toBeNull();
});

it('mapea disputas y códigos de rechazo', function (): void {
    expect(StripeMapper::dispute(['id' => 'dp_1', 'status' => 'warning_needs_response'])->status)->toBe(DisputeStatus::Open)
        ->and(StripeMapper::dispute(['id' => 'dp_1', 'status' => 'under_review'])->status)->toBe(DisputeStatus::UnderReview)
        ->and(StripeMapper::dispute(['id' => 'dp_1', 'status' => 'prevented'])->status)->toBe(DisputeStatus::Closed)
        ->and(StripeMapper::dispute(['id' => 'dp_1', 'status' => 'lost', 'charge' => 'ch_1'])->attemptExternalId)->toBe('ch_1')
        ->and(StripeMapper::failureCategory('stolen_card'))->toBe(FailureCategory::SuspectedFraud)
        ->and(StripeMapper::failureCategory('incorrect_cvc'))->toBe(FailureCategory::InvalidPaymentData)
        ->and(StripeMapper::failureCategory('do_not_honor'))->toBe(FailureCategory::Declined)
        ->and(StripeMapper::amount(1050))->toBe('10.50')
        ->and(StripeMapper::minorUnits('1234.56'))->toBe(123456);
});

it('reembolsa el cargo exitoso con la llave de idempotencia, el motivo traducido y la referencia del CRM', function (): void {
    $payment = Payment::factory()->succeeded()->create(['provider' => PaymentProvider::Stripe, 'external_id' => 'pi_9', 'amount' => '500.00']);
    PaymentAttempt::query()->create(['payment_id' => $payment->id, 'provider' => PaymentProvider::Stripe, 'external_id' => 'ch_9',
        'attempt_number' => 1, 'status' => 'succeeded', 'initiated_by' => 'donor']);
    stripeHttp()->respond('POST', '/v1/refunds', ['id' => 're_1', 'object' => 'refund', 'status' => 'succeeded', 'amount' => 20000,
        'payment_intent' => 'pi_9', 'created' => 1_790_000_000, 'metadata' => []]);
    $key = 'refund-'.Str::uuid();

    $refund = app(RequestRefund::class)->handle($payment, ['amount' => '200', 'reason' => RefundReason::DuplicateCharge->value, 'idempotency_key' => $key], userWithRole(Role::Accountant));

    $request = stripeHttp()->requestsTo('POST', '/v1/refunds')[0];
    expect(FakeStripeHttpClient::header($request, 'Idempotency-Key'))->toBe($key)
        ->and($request['params']['charge'])->toBe('ch_9')
        ->and($request['params']['amount'])->toBe(20000)
        ->and($request['params']['reason'])->toBe('duplicate')
        ->and($request['params']['metadata']['crm_refund_id'])->toBe((string) $refund->id)
        ->and($refund->status)->toBe(RefundStatus::Succeeded)
        ->and($refund->external_id)->toBe('re_1');
});

it('sin conexión con Stripe el reembolso queda en proceso para reenviarse con la misma llave', function (): void {
    $payment = Payment::factory()->succeeded()->create(['provider' => PaymentProvider::Stripe, 'external_id' => 'pi_8', 'amount' => '500.00']);
    stripeHttp()->goOffline();

    $refund = app(RequestRefund::class)->handle($payment, ['amount' => '50', 'reason' => 'donor_request', 'idempotency_key' => 'refund-'.Str::uuid()], userWithRole(Role::Administrator));

    expect($refund->status)->toBe(RefundStatus::Pending)->and(Refund::query()->count())->toBe(1);
});

it('los errores de conexión se traducen sin exponer datos del proveedor', function (): void {
    stripeHttp()->goOffline();

    /** @var StripeGateway $gateway */
    $gateway = app(GatewayRegistry::class)->get(PaymentProvider::Stripe);

    expect(fn () => $gateway->fetch('payment_intent', 'pi_x'))->toThrow(ProviderUnavailableException::class, 'Stripe no respondió');
});

it('enruta cada tipo de evento al recurso que se debe consultar', function (string $type, array $object, ?array $expected): void {
    expect(StripeMapper::route($type, $object))->toBe($expected);
})->with([
    'sesión completada' => ['checkout.session.completed', ['id' => 'cs_1'], ['checkout_session', 'cs_1']],
    'cargo exitoso' => ['charge.succeeded', ['id' => 'ch_1', 'payment_intent' => 'pi_1'], ['payment_intent', 'pi_1']],
    'cargo reembolsado' => ['charge.refunded', ['id' => 'ch_1', 'payment_intent' => 'pi_1'], ['payment_intent', 'pi_1']],
    'reembolso' => ['refund.updated', ['id' => 're_1'], ['refund', 're_1']],
    'disputa' => ['charge.dispute.created', ['id' => 'dp_1'], ['dispute', 'dp_1']],
    'factura' => ['invoice.paid', ['id' => 'in_1'], ['invoice', 'in_1']],
    'suscripción' => ['customer.subscription.updated', ['id' => 'sub_1'], ['subscription', 'sub_1']],
    'ajeno al CRM' => ['account.updated', ['id' => 'acct_1'], null],
]);
