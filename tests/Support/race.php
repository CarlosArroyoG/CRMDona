<?php

declare(strict_types=1);

/*
 * Proceso participante de las pruebas de concurrencia (tests/Concurrency).
 * Solo lo ejecutan esas pruebas, con APP_ENV=testing y la base crm_testing;
 * no es un comando de Artisan ni es accesible desde la web.
 *
 * Uso: php tests/Support/race.php <escenario> <argumentos-json> <llave-barrera>
 *
 * Espera en la barrera (candado consultivo de PostgreSQL que retiene el
 * proceso padre) para que ambos participantes ejecuten la operación al mismo
 * tiempo, y escribe el resultado como JSON en la última línea de su salida.
 */

use App\Actions\Incidents\OpenPaymentIncident;
use App\Actions\Payments\ApplyProviderSnapshot;
use App\Actions\Payments\CreateDonationFromPayment;
use App\Actions\Payments\StartOneTimeDonation;
use App\Actions\Refunds\RequestRefund;
use App\Enums\IncidentType;
use App\Enums\PaymentProvider;
use App\Models\Payment;
use App\Models\User;
use App\Payments\GatewayRegistry;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

if (! app()->environment('testing') || config('database.connections.pgsql.database') !== 'crm_testing') {
    fwrite(STDERR, "Solo se ejecuta contra crm_testing.\n");
    exit(2);
}

[$scenario, $json, $barrier] = [$argv[1] ?? '', $argv[2] ?? '{}', (int) ($argv[3] ?? 0)];
/** @var array<string, mixed> $args */
$args = json_decode($json, true, 16, JSON_THROW_ON_ERROR);

// Barrera: se bloquea hasta que el proceso padre libere su candado exclusivo.
DB::select('select pg_advisory_lock_shared(?)', [$barrier]);
DB::select('select pg_advisory_unlock_shared(?)', [$barrier]);

try {
    $result = match ($scenario) {
        'request-refund' => app(RequestRefund::class)->handle(
            Payment::query()->findOrFail((int) $args['payment_id']),
            ['amount' => $args['amount'], 'reason' => 'donor_request', 'idempotency_key' => $args['key']],
            User::query()->findOrFail((int) $args['actor_id']),
        )->id,
        'insert-refund' => DB::table('refunds')->insertGetId([
            'payment_id' => $args['payment_id'], 'provider' => 'fake', 'amount' => $args['amount'], 'status' => 'pending',
            'reason' => 'donor_request', 'source' => 'crm', 'requested_by_id' => $args['actor_id'], 'requested_at' => now(),
            'idempotency_key' => $args['key'], 'created_at' => now(), 'updated_at' => now(),
        ]),
        'start-donation' => app(StartOneTimeDonation::class)->handle($args)['payment']->id,
        'sync-payment' => app(ApplyProviderSnapshot::class)->handle(
            PaymentProvider::Fake,
            app(GatewayRegistry::class)->get(PaymentProvider::Fake)->fetch('payment', (string) $args['external_id']),
        )['payment_id'],
        'webhook' => app(HttpKernel::class)->handle(Request::create(
            '/webhooks/payments/fake', 'POST', [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_FAKE_SIGNATURE' => $args['signature']],
            (string) $args['body'],
        ))->getStatusCode(),
        'create-donation' => DB::transaction(fn () => app(CreateDonationFromPayment::class)
            ->handle(Payment::query()->lockForUpdate()->findOrFail((int) $args['payment_id']))->id),
        'open-incident' => app(OpenPaymentIncident::class)->handle(
            IncidentType::DisputeOpened,
            (string) $args['key'],
            payment: Payment::query()->findOrFail((int) $args['payment_id']),
        )->id,
        default => throw new InvalidArgumentException("Escenario desconocido: {$scenario}"),
    };

    echo PHP_EOL.json_encode(['ok' => true, 'result' => $result]);
} catch (Throwable $exception) {
    echo PHP_EOL.json_encode(['ok' => false, 'error' => $exception::class, 'message' => mb_substr($exception->getMessage(), 0, 300)]);
}
