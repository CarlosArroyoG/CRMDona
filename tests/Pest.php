<?php

declare(strict_types=1);

use App\Actions\Payments\StartOneTimeDonation;
use App\Actions\Subscriptions\StartMonthlyDonation;
use App\Enums\PaymentProvider;
use App\Enums\Role;
use App\Models\Donor;
use App\Models\Export;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use App\Payments\GatewayRegistry;
use App\Payments\Gateways\FakeGateway;
use App\Payments\Gateways\FakeScenario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

use function Pest\Laravel\call;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

// Concurrencia real entre procesos: sin transacción de prueba (ver tests/Concurrency).
pest()->extend(TestCase::class)->in('Concurrency');

/**
 * Usuario activo con el rol indicado (correo ficticio de example.com).
 */
function userWithRole(Role $role): User
{
    return User::factory()->withRole($role)->create();
}

/**
 * Pasarela simulada (sin Internet). Su estado vive en la caché de pruebas.
 */
function fakeGateway(): FakeGateway
{
    /** @var FakeGateway $gateway */
    $gateway = app(GatewayRegistry::class)->get(PaymentProvider::Fake);

    return $gateway;
}

/**
 * Inicia un donativo único con la pasarela simulada. `scenario` fija el
 * resultado que simulará el proveedor.
 *
 * @param  array<string, mixed>  $input
 */
function startFakeDonation(array $input = [], FakeScenario ...$scenarios): Payment
{
    if ($scenarios !== []) {
        fakeGateway()->willReturn(...$scenarios);
    }

    return app(StartOneTimeDonation::class)->handle([
        'provider' => PaymentProvider::Fake->value,
        'donor_id' => $input['donor_id'] ?? Donor::factory()->create()->id,
        'amount' => '500.00',
        'idempotency_key' => 'test-'.Str::uuid()->toString(),
        ...$input,
    ])['payment'];
}

/**
 * Inicia un donativo mensual con la pasarela simulada.
 *
 * @param  array<string, mixed>  $input
 */
function startFakeMonthlyDonation(array $input = [], FakeScenario ...$scenarios): Subscription
{
    if ($scenarios !== []) {
        fakeGateway()->willReturn(...$scenarios);
    }

    return app(StartMonthlyDonation::class)->handle([
        'provider' => PaymentProvider::Fake->value,
        'donor_id' => $input['donor_id'] ?? Donor::factory()->create()->id,
        'amount' => '300.00',
        'idempotency_key' => 'test-'.Str::uuid()->toString(),
        ...$input,
    ])['subscription'];
}

/**
 * Cola real en base de datos (como en producción la cola es asíncrona): la
 * respuesta HTTP del webhook no depende del resultado del procesamiento.
 * `tries` es el número de intentos del Job antes de marcarse fallido.
 */
function useDatabaseQueue(int $tries = 1): void
{
    config(['queue.default' => 'database', 'payments.webhooks.tries' => $tries]);
}

/**
 * Ejecuta un worker real hasta vaciar la cola.
 *
 * `--memory` alto: el worker corre dentro del proceso de PHPUnit, cuya memoria
 * crece a lo largo de la suite. Con el límite por defecto (128 MB) el worker se
 * detenía después del primer job al final de la suite completa y dejaba jobs
 * sin procesar (prueba intermitente de WebhookInboxTest).
 */
function runQueueWorker(): void
{
    Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--sleep' => 0, '--memory' => 4096]);
}

/**
 * Entrega una notificación firmada de la pasarela simulada por HTTP, como lo
 * haría el proveedor.
 *
 * @param  array<string, mixed>  $extra
 * @return TestResponse<Response>
 */
function deliverFakeWebhook(string $eventType, string $resourceType, string $resourceId, ?string $eventId = null, array $extra = []): TestResponse
{
    $webhook = fakeGateway()->webhook($eventType, $resourceType, $resourceId, $eventId, $extra);

    return call('POST', '/webhooks/payments/fake', [], [], [], array_merge(
        ['CONTENT_TYPE' => 'application/json'],
        ['HTTP_'.strtoupper(str_replace('-', '_', FakeGateway::SIGNATURE_HEADER)) => $webhook['headers'][FakeGateway::SIGNATURE_HEADER]],
    ), $webhook['body']);
}

/**
 * Contenido CSV de una exportación terminada, como lo arma Filament:
 * encabezados y luego los bloques.
 */
function exportedCsv(Export $export): string
{
    $disk = Storage::disk('local');
    $files = collect($disk->files($export->getFileDirectory()))
        ->filter(fn (string $file): bool => str_ends_with($file, '.csv'))
        ->sortBy(fn (string $file): string => str_ends_with($file, 'headers.csv') ? '0' : '1'.$file)
        ->values();

    return $files->map(fn (string $file): string => (string) $disk->get($file))->implode('');
}
