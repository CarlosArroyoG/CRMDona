<?php

declare(strict_types=1);

use App\Actions\Payments\ApplyProviderSnapshot;
use App\Actions\Payments\SyncPayment;
use App\Actions\Webhooks\RetryWebhookEvent;
use App\Enums\IncidentType;
use App\Enums\PaymentKind;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Enums\WebhookEventStatus;
use App\Jobs\ProcessWebhookEvent;
use App\Models\Donation;
use App\Models\Payment;
use App\Models\PaymentIncident;
use App\Models\WebhookEvent;
use App\Payments\Data\PaymentSnapshot;
use App\Payments\GatewayRegistry;
use App\Payments\Gateways\FakeGateway;
use App\Payments\Gateways\FakeScenario;
use App\Support\AuditOrigin;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\call;
use function Pest\Laravel\postJson;

it('rechaza notificaciones sin firma válida y no guarda nada', function (): void {
    $webhook = fakeGateway()->webhook('payment.succeeded', 'payment', 'fake_pay_x');

    call('POST', '/webhooks/payments/fake', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_FAKE_SIGNATURE' => 'firma-falsa',
    ], $webhook['body'])->assertStatus(400);

    call('POST', '/webhooks/payments/fake', [], [], [], ['CONTENT_TYPE' => 'application/json'], $webhook['body'])
        ->assertStatus(400);

    expect(WebhookEvent::query()->count())->toBe(0);
});

it('responde 404 a proveedores desconocidos o deshabilitados', function (string $provider): void {
    postJson("/webhooks/payments/{$provider}", ['id' => 'evt_1'])->assertNotFound();
    expect(WebhookEvent::query()->count())->toBe(0);
})->with(['paypal', 'stripe', 'mercado_pago']);

it('no exige CSRF ni sesión: la autenticación es la firma', function (): void {
    $payment = startFakeDonation([], FakeScenario::Pending);

    deliverFakeWebhook('payment.updated', 'payment', (string) $payment->external_id)->assertOk();
});

it('guarda la notificación y la procesa en la cola, respondiendo de inmediato', function (): void {
    Queue::fake();
    $payment = startFakeDonation([], FakeScenario::Pending);

    deliverFakeWebhook('payment.updated', 'payment', (string) $payment->external_id, 'evt_cola')->assertOk();

    $event = WebhookEvent::query()->sole();
    expect($event->status)->toBe(WebhookEventStatus::Pending)
        ->and($event->external_event_id)->toBe('evt_cola');
    Queue::assertPushed(ProcessWebhookEvent::class, 1);
});

it('la misma notificación dos veces se guarda y procesa una sola vez', function (): void {
    $payment = startFakeDonation([], FakeScenario::Pending);
    fakeGateway()->donorAttempt((string) $payment->external_id, FakeScenario::Success);

    deliverFakeWebhook('payment.succeeded', 'payment', (string) $payment->external_id, 'evt_dup')->assertOk();
    deliverFakeWebhook('payment.succeeded', 'payment', (string) $payment->external_id, 'evt_dup')->assertOk();

    $event = WebhookEvent::query()->sole();
    expect($event->status)->toBe(WebhookEventStatus::Processed)
        ->and($event->attempts)->toBe(1)
        ->and($event->payment_id)->toBe($payment->id)
        ->and(Donation::query()->count())->toBe(1);
});

it('dos notificaciones distintas del mismo pago no duplican el donativo', function (): void {
    $payment = startFakeDonation([], FakeScenario::Pending);
    fakeGateway()->donorAttempt((string) $payment->external_id, FakeScenario::Success);

    deliverFakeWebhook('payment_intent.succeeded', 'payment', (string) $payment->external_id);
    deliverFakeWebhook('charge.succeeded', 'payment', (string) $payment->external_id);

    expect(WebhookEvent::query()->count())->toBe(2)
        ->and(Donation::query()->count())->toBe(1);
});

it('un Job reintentado sobre un evento ya procesado no cambia nada', function (): void {
    $payment = startFakeDonation([], FakeScenario::Pending);
    fakeGateway()->donorAttempt((string) $payment->external_id, FakeScenario::Success);
    deliverFakeWebhook('payment.succeeded', 'payment', (string) $payment->external_id, 'evt_retry');
    $event = WebhookEvent::query()->sole();

    (new ProcessWebhookEvent($event->id))->handle(...array_map(app(...), [
        GatewayRegistry::class, ApplyProviderSnapshot::class, AuditOrigin::class,
    ]));
    dispatch_sync(new ProcessWebhookEvent($event->id));

    expect($event->fresh()?->attempts)->toBe(1)
        ->and(Donation::query()->count())->toBe(1);
});

it('fuera de orden: una notificación vieja que llega después no retrocede el pago', function (): void {
    $payment = startFakeDonation([], FakeScenario::Pending);
    fakeGateway()->donorAttempt((string) $payment->external_id, FakeScenario::Success);

    // Llega primero "exitoso" y después el "pendiente" viejo: se consulta el estado actual.
    deliverFakeWebhook('payment.succeeded', 'payment', (string) $payment->external_id, 'evt_nuevo');
    deliverFakeWebhook('payment.pending', 'payment', (string) $payment->external_id, 'evt_viejo');

    expect($payment->fresh()?->status)->toBe(PaymentStatus::Succeeded)
        ->and(Donation::query()->count())->toBe(1)
        ->and(PaymentIncident::query()->count())->toBe(0);
});

it('un estado consultado con fecha anterior a la ya aplicada no retrocede el pago', function (): void {
    $payment = startFakeDonation([], FakeScenario::Success);
    $older = CarbonImmutable::parse((string) $payment->provider_updated_at)->subMinute();

    app(SyncPayment::class)->handle(PaymentProvider::Fake, new PaymentSnapshot(
        kind: PaymentKind::OneTime,
        status: PaymentStatus::Pending,
        providerStatus: 'pending',
        externalId: $payment->external_id,
        providerUpdatedAt: $older,
    ));

    expect($payment->fresh()?->status)->toBe(PaymentStatus::Succeeded)
        ->and(PaymentIncident::query()->count())->toBe(0);
});

it('un estado actual que contradice uno final no se aplica y abre incidencia', function (): void {
    $payment = startFakeDonation([], FakeScenario::Success);
    fakeGateway()->setPaymentStatus((string) $payment->external_id, PaymentStatus::Cancelled);

    deliverFakeWebhook('payment.canceled', 'payment', (string) $payment->external_id);

    expect($payment->fresh()?->status)->toBe(PaymentStatus::Succeeded)
        ->and(PaymentIncident::query()->sole()->type)->toBe(IncidentType::StateInconsistency);
});

it('ignora eventos que no afectan al CRM (sin recurso o de un recurso desconocido)', function (): void {
    deliverFakeWebhook('account.updated', 'account', 'acct_1')->assertOk();
    deliverFakeWebhook('payment.succeeded', 'payment', 'fake_pay_de_otro_sistema')->assertOk();

    expect(WebhookEvent::query()->pluck('status')->all())->toBe([WebhookEventStatus::Ignored, WebhookEventStatus::Ignored])
        ->and(Payment::query()->count())->toBe(0);
});

it('marca como fallida la notificación cuyo proveedor no responde y abre una sola incidencia', function (): void {
    $payment = startFakeDonation([], FakeScenario::Pending);
    fakeGateway()->setUnavailable();
    useDatabaseQueue(tries: 1);

    deliverFakeWebhook('payment.updated', 'payment', (string) $payment->external_id, 'evt_caido')->assertOk();
    deliverFakeWebhook('payment.updated', 'payment', (string) $payment->external_id, 'evt_caido_2')->assertOk();
    runQueueWorker();

    expect(WebhookEvent::query()->where('status', WebhookEventStatus::Failed->value)->count())->toBe(2)
        ->and(WebhookEvent::query()->firstOrFail()->last_error)->toContain('no está disponible')
        ->and(PaymentIncident::query()->where('type', IncidentType::ProviderUnavailable->value)->count())->toBe(1);
});

it('reprocesar una notificación fallida la completa sin duplicar', function (): void {
    $payment = startFakeDonation([], FakeScenario::Pending);
    fakeGateway()->donorAttempt((string) $payment->external_id, FakeScenario::Success);
    fakeGateway()->setUnavailable();
    useDatabaseQueue(tries: 1);
    deliverFakeWebhook('payment.succeeded', 'payment', (string) $payment->external_id, 'evt_reintento')->assertOk();
    runQueueWorker();
    expect(WebhookEvent::query()->sole()->status)->toBe(WebhookEventStatus::Failed);

    fakeGateway()->setUnavailable(false);
    config(['queue.default' => 'sync']);
    app(RetryWebhookEvent::class)->handle(WebhookEvent::query()->sole(), userWithRole(Role::Administrator));

    expect(WebhookEvent::query()->sole()->status)->toBe(WebhookEventStatus::Processed)
        ->and(Donation::query()->count())->toBe(1);
});

it('guarda solo la lista permitida del payload: nada de tarjeta, secretos ni datos personales', function (): void {
    $payment = startFakeDonation([], FakeScenario::Pending);

    deliverFakeWebhook('payment.updated', 'payment', (string) $payment->external_id, 'evt_sucio', [
        'data' => ['object' => [
            'id' => $payment->external_id,
            'status' => 'pending',
            'card' => ['number' => '4242424242424242', 'cvc' => '123', 'exp_month' => 12],
            'billing_details' => ['name' => 'Rosa Secreta', 'email' => 'rosa@example.com'],
            'client_secret' => 'pi_secret_abc',
            'metadata' => ['crm_payment_id' => $payment->id, 'nota' => 'privada'],
        ]],
        'authorization' => 'Bearer sk_test_nunca',
    ]);

    $stored = WebhookEvent::query()->sole();
    $json = json_encode($stored->payload, JSON_THROW_ON_ERROR);

    foreach (['4242424242424242', '123"', 'Rosa', 'rosa@example.com', 'pi_secret', 'sk_test', 'privada', 'billing_details', 'card'] as $forbidden) {
        expect($json)->not->toContain($forbidden);
    }

    expect($stored->payload)->toMatchArray(['id' => 'evt_sucio', 'type' => 'payment.updated', 'resource_id' => $payment->external_id])
        ->and($stored->payload['data']['object'])->toBe([
            'id' => $payment->external_id, 'status' => 'pending', 'metadata' => ['crm_payment_id' => $payment->id],
        ])
        ->and($stored->provider_created_at)->not->toBeNull();
});

it('no permite enviar notificaciones simuladas fuera de local y testing', function (): void {
    app()->detectEnvironment(fn (): string => 'production');
    app(GatewayRegistry::class)->flush();

    postJson('/webhooks/payments/fake', ['id' => 'evt'], [FakeGateway::SIGNATURE_HEADER => 'x'])->assertNotFound();
});
