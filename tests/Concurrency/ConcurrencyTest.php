<?php

declare(strict_types=1);

use App\Actions\Payments\StartOneTimeDonation;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentIncident;
use App\Models\Refund;
use App\Models\WebhookEvent;
use App\Payments\GatewayRegistry;
use App\Payments\Gateways\FakeGateway;
use App\Payments\Gateways\FakeScenario;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concurrency\Race;

/*
 * Condiciones de carrera reales: dos procesos PHP independientes contra
 * PostgreSQL al mismo tiempo. Estas pruebas no usan RefreshDatabase (una
 * transacción de prueba ocultaría los datos a los otros procesos): los datos
 * se confirman y se limpian al terminar cada prueba.
 */

beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
    truncateAllTables();
    config(['payments.providers.fake.store' => 'file']);
    app(GatewayRegistry::class)->flush();
    fakeGatewayForRace()->reset();
});

afterEach(function (): void {
    truncateAllTables();
    fakeGatewayForRace()->reset();
});

function truncateAllTables(): void
{
    $tables = collect(DB::select("select tablename from pg_tables where schemaname = 'public' and tablename <> 'migrations'"))
        ->map(fn (object $row): string => '"'.((array) $row)['tablename'].'"')->implode(', ');

    DB::statement("truncate {$tables} restart identity cascade");
}

/**
 * Ambos procesos terminaron sin excepción. Si alguno falla, el mensaje muestra
 * la excepción y el texto del proceso hijo (no solo "false is true").
 *
 * @param  array{ok: bool, result: mixed, error: string|null, message: string|null}  ...$results
 */
function raceSucceeded(array ...$results): void
{
    foreach ($results as $index => $result) {
        expect($result['ok'])->toBeTrue("El proceso {$index} falló: ".($result['error'] ?? '?').' — '.($result['message'] ?? ''));
    }
}

function fakeGatewayForRace(): FakeGateway
{
    /** @var FakeGateway $gateway */
    $gateway = app(GatewayRegistry::class)->get(PaymentProvider::Fake);

    return $gateway;
}

function succeededPaymentForRace(string $amount = '500.00'): Payment
{
    fakeGatewayForRace()->willReturn(FakeScenario::Success);

    return app(StartOneTimeDonation::class)->handle([
        'provider' => 'fake', 'donor_id' => Donor::factory()->create()->id, 'amount' => $amount, 'idempotency_key' => 'race-'.Str::uuid(),
    ])['payment'];
}

it('dos reembolsos simultáneos que juntos exceden el pago: solo uno procede', function (): void {
    $payment = succeededPaymentForRace('500.00');
    $actor = userWithRole(Role::Accountant);

    [$a, $b] = Race::run('request-refund',
        ['payment_id' => $payment->id, 'amount' => '300.00', 'key' => 'refund-a-'.Str::uuid(), 'actor_id' => $actor->id],
        ['payment_id' => $payment->id, 'amount' => '300.00', 'key' => 'refund-b-'.Str::uuid(), 'actor_id' => $actor->id],
    );

    expect([$a['ok'], $b['ok']])->toEqualCanonicalizing([true, false])
        ->and(($a['ok'] ? $b : $a)['error'])->toBe(ValidationException::class)
        ->and(Refund::query()->count())->toBe(1)
        ->and(Refund::query()->sum('amount'))->toEqual('300.00')
        ->and(fakeGatewayForRace()->refundIdsFor((string) $payment->external_id))->toHaveCount(1);
});

it('el trigger de PostgreSQL impide exceder el pago aunque dos procesos inserten a la vez sin pasar por la Action', function (): void {
    $payment = Payment::factory()->succeeded()->create(['amount' => '100.00']);
    $actor = userWithRole(Role::Administrator);

    [$a, $b] = Race::run('insert-refund',
        ['payment_id' => $payment->id, 'amount' => '60.00', 'key' => 'raw-a', 'actor_id' => $actor->id],
        ['payment_id' => $payment->id, 'amount' => '60.00', 'key' => 'raw-b', 'actor_id' => $actor->id],
    );

    expect([$a['ok'], $b['ok']])->toEqualCanonicalizing([true, false])
        ->and(($a['ok'] ? $b : $a)['message'])->toContain('no puede superar')
        ->and(DB::table('refunds')->sum('amount'))->toEqual('60.00');
});

it('la misma solicitud de reembolso enviada dos veces a la vez crea un solo reembolso', function (): void {
    $payment = succeededPaymentForRace('500.00');
    $actor = userWithRole(Role::Accountant);
    $args = ['payment_id' => $payment->id, 'amount' => '100.00', 'key' => 'refund-same-'.Str::uuid(), 'actor_id' => $actor->id];

    [$a, $b] = Race::run('request-refund', $args, $args);

    raceSucceeded($a, $b);
    expect($a['result'])->toBe($b['result'])
        ->and(Refund::query()->count())->toBe(1)
        ->and(fakeGatewayForRace()->refundIdsFor((string) $payment->external_id))->toHaveCount(1);
});

it('doble clic simultáneo con la misma llave: un solo pago, un solo cobro y un solo donativo', function (): void {
    $args = ['provider' => 'fake', 'donor_id' => Donor::factory()->create()->id, 'amount' => '250', 'idempotency_key' => 'click-'.Str::uuid()];

    [$a, $b] = Race::run('start-donation', $args, $args);

    raceSucceeded($a, $b);
    expect($a['result'])->toBe($b['result'])
        ->and(Payment::query()->count())->toBe(1)
        ->and(PaymentAttempt::query()->count())->toBe(1)
        ->and(Donation::query()->count())->toBe(1);
});

it('la misma notificación entregada dos veces a la vez se guarda y procesa una sola vez', function (): void {
    fakeGatewayForRace()->willReturn(FakeScenario::Pending);
    $payment = app(StartOneTimeDonation::class)->handle([
        'provider' => 'fake', 'donor_id' => Donor::factory()->create()->id, 'amount' => '400', 'idempotency_key' => 'race-'.Str::uuid(),
    ])['payment'];
    fakeGatewayForRace()->donorAttempt((string) $payment->external_id, FakeScenario::Success);
    $webhook = fakeGatewayForRace()->webhook('payment.succeeded', 'payment', (string) $payment->external_id, 'evt_race');
    $args = ['body' => $webhook['body'], 'signature' => $webhook['headers'][FakeGateway::SIGNATURE_HEADER]];

    [$a, $b] = Race::run('webhook', $args, $args);

    expect([$a['result'], $b['result']])->toBe([200, 200])
        ->and(WebhookEvent::query()->count())->toBe(1)
        ->and(WebhookEvent::query()->sole()->attempts)->toBe(1)
        ->and(Donation::query()->count())->toBe(1);
});

it('dos notificaciones distintas del mismo pago procesadas a la vez generan un solo donativo', function (): void {
    fakeGatewayForRace()->willReturn(FakeScenario::Pending);
    $payment = app(StartOneTimeDonation::class)->handle([
        'provider' => 'fake', 'donor_id' => Donor::factory()->create()->id, 'amount' => '400', 'idempotency_key' => 'race-'.Str::uuid(),
    ])['payment'];
    fakeGatewayForRace()->donorAttempt((string) $payment->external_id, FakeScenario::Success);
    $args = ['external_id' => $payment->external_id];

    [$a, $b] = Race::run('sync-payment', $args, $args);

    raceSucceeded($a, $b);
    expect(Donation::query()->where('payment_id', $payment->id)->count())->toBe(1)
        ->and(PaymentAttempt::query()->where('payment_id', $payment->id)->count())->toBe(1)
        ->and($payment->fresh()?->status)->toBe(PaymentStatus::Succeeded);
});

it('dos procesos creando el donativo del mismo pago a la vez: uno solo', function (): void {
    $payment = Payment::factory()->succeeded()->create();

    [$a, $b] = Race::run('create-donation', ['payment_id' => $payment->id], ['payment_id' => $payment->id]);

    raceSucceeded($a, $b);
    expect($a['result'])->toBe($b['result'])
        ->and(Donation::query()->count())->toBe(1);
});

it('la misma incidencia abierta a la vez por dos procesos: una incidencia y una alerta', function (): void {
    $admin = userWithRole(Role::Administrator);
    $payment = Payment::factory()->create();
    $args = ['payment_id' => $payment->id, 'key' => "dispute:race:{$payment->id}"];

    [$a, $b] = Race::run('open-incident', $args, $args);

    raceSucceeded($a, $b);
    expect($a['result'])->toBe($b['result'])
        ->and(PaymentIncident::query()->count())->toBe(1)
        ->and(DatabaseNotification::query()->where('notifiable_id', $admin->id)->count())->toBe(1);
});
