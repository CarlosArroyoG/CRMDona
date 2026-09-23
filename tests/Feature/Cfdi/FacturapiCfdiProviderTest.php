<?php

declare(strict_types=1);

use App\Actions\Cfdi\BuildDonationCfdiDraft;
use App\Actions\Cfdi\RequestDonationCfdi;
use App\Cfdi\CfdiProviderRegistry;
use App\Cfdi\Data\CancellationResult;
use App\Cfdi\Data\CfdiDraft;
use App\Cfdi\Exceptions\CfdiProviderUnavailableException;
use App\Cfdi\Exceptions\CfdiRejectedException;
use App\Cfdi\Providers\FacturapiCfdiProvider;
use App\Enums\CfdiCancellationMotive;
use App\Enums\CfdiStatus;
use App\Enums\Role;
use App\Jobs\StampCfdi;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\OrganizationSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

const FACTURAPI_TEST_KEY = 'sk_test_ficticia_para_pruebas';

beforeEach(function (): void {
    Http::preventStrayRequests();
    Storage::fake('local');
    config(['cfdi.facturapi.key' => FACTURAPI_TEST_KEY, 'cfdi.facturapi.base_url' => 'https://www.facturapi.io/v2']);
});

function facturapi(): FacturapiCfdiProvider
{
    return new FacturapiCfdiProvider(FACTURAPI_TEST_KEY);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function facturapiDraft(array $overrides = []): CfdiDraft
{
    return new CfdiDraft(...[
        'donationId' => 1, 'series' => 'DON', 'folio' => '7', 'issuerRfc' => 'FPR010101AAA', 'issuerName' => 'FUNDACION DE PRUEBA',
        'issuerRegime' => '603', 'expeditionPostalCode' => '62000', 'receiverRfc' => 'AAA010101AAA', 'receiverName' => 'DONANTE FICTICIO',
        'receiverRegime' => '605', 'receiverPostalCode' => '62000', 'cfdiUse' => 'D04', 'paymentForm' => '01', 'paymentMethod' => 'PUE',
        'voucherType' => 'I', 'currency' => 'MXN', 'productCode' => '84101600', 'unitCode' => 'M4', 'description' => 'Donativo para el fondo general',
        'quantity' => '1', 'unitValue' => '1500.50', 'total' => '1500.50', 'taxObject' => '01', 'authorizationNumber' => '600-04-02-2026-0001',
        'authorizationDate' => '2026-01-15', 'legend' => BuildDonationCfdiDraft::DONATARIA_LEGEND, ...$overrides,
    ]);
}

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function facturapiInvoice(string $status = 'valid', array $extra = []): array
{
    return ['id' => 'inv_123', 'status' => $status, 'uuid' => 'a1b2c3d4-0000-4000-8000-000000000001', 'total' => 1500.5,
        'external_id' => 'k1', 'cancellation_status' => 'none', 'stamp' => ['date' => '2026-09-23T18:00:00.000Z'], ...$extra];
}

/**
 * @param  array<string, mixed>|null  $existing
 * @param  array{0: int, 1: array<string, mixed>}|Closure|null  $create
 */
function fakeFacturapi(?array $existing = null, array|Closure|null $create = null): void
{
    Http::fake(function (Request $request) use ($existing, $create) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        return match (true) {
            $request->method() === 'GET' && $path === '/v2/invoices' => Http::response(['data' => $existing !== null ? [$existing] : [], 'page' => 1, 'total_pages' => 1]),
            $request->method() === 'POST' && $path === '/v2/invoices' => $create instanceof Closure ? $create() : Http::response(($create ?? [200, facturapiInvoice()])[1], ($create ?? [200])[0]),
            str_ends_with((string) $path, '/xml') => Http::response('<cfdi:Comprobante Version="4.0"/>', 200, ['Content-Type' => 'application/xml']),
            str_ends_with((string) $path, '/pdf') => Http::response('%PDF-1.7 prueba', 200, ['Content-Type' => 'application/pdf']),
            default => Http::response(['message' => 'no esperado'], 500),
        };
    });
}

it('arma el cuerpo CFDI 4.0 con el complemento de donatarias 1.1 y la leyenda en el PDF', function (): void {
    $payload = facturapi()->payload(facturapiDraft(['relatedUuids' => ['UUID-ORIGINAL'], 'relationType' => '04']), 'donation:1:cfdi');

    expect($payload)->toMatchArray([
        'type' => 'I', 'payment_form' => '01', 'payment_method' => 'PUE', 'use' => 'D04', 'currency' => 'MXN', 'series' => 'DON', 'folio_number' => 7,
        'external_id' => 'donation:1:cfdi', 'idempotency_key' => 'donation:1:cfdi',
        'related_documents' => [['relationship' => '04', 'documents' => ['UUID-ORIGINAL']]],
    ])->and($payload['customer'])->toBe(['legal_name' => 'DONANTE FICTICIO', 'tax_id' => 'AAA010101AAA', 'tax_system' => '605', 'address' => ['zip' => '62000']])
        ->and($payload['items'][0]['product'])->toMatchArray(['product_key' => '84101600', 'unit_key' => 'M4', 'price' => 1500.5, 'taxability' => '01', 'taxes' => []])
        ->and($payload['complements'][0]['type'])->toBe('custom')
        ->and($payload['complements'][0]['data'])->toContain('<donat:Donatarias xmlns:donat="http://www.sat.gob.mx/donat" version="1.1"')
        ->toContain('noAutorizacion="600-04-02-2026-0001"')->toContain('fechaAutorizacion="2026-01-15"')
        ->and($payload['pdf_custom_section'])->toContain('600-04-02-2026-0001')->toContain('fines propios de su objeto social')
        ->and(json_encode($payload))->toContain('"price":1500.5');
});

it('timbra: busca primero por llave, crea, y descarga XML y PDF', function (): void {
    fakeFacturapi();

    $result = facturapi()->stamp(facturapiDraft(), 'k1');

    expect($result->uuid)->toBe('A1B2C3D4-0000-4000-8000-000000000001')->and($result->externalId)->toBe('inv_123')
        ->and($result->xml)->toContain('Comprobante')->and($result->pdf)->toStartWith('%PDF')
        ->and($result->stampedAt->toIso8601String())->toBe('2026-09-23T18:00:00+00:00');
    Http::assertSent(fn (Request $request) => $request->method() === 'GET' && str_contains($request->url(), 'external_id=k1'));
    Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request->hasHeader('Authorization', 'Bearer '.FACTURAPI_TEST_KEY));
});

it('reintento seguro: si la factura ya existe con esa llave, la adopta sin crear otra', function (): void {
    fakeFacturapi(existing: facturapiInvoice());

    expect(facturapi()->stamp(facturapiDraft(), 'k1')->externalId)->toBe('inv_123');
    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
});

it('resultado incierto o temporal → no disponible (202, 409 al crear, 5xx, 429, sin respuesta, pendiente)', function (Closure $scenario): void {
    $scenario();

    expect(fn () => facturapi()->stamp(facturapiDraft(), 'k1'))->toThrow(CfdiProviderUnavailableException::class);
})->with([
    '202 rescate por intermitencia' => [fn () => fakeFacturapi(create: [202, facturapiInvoice('pending', ['uuid' => null])])],
    '409 al crear' => [fn () => fakeFacturapi(create: [409, ['message' => 'conflicto']])],
    '500' => [fn () => fakeFacturapi(create: [500, ['message' => 'error']])],
    '429' => [fn () => fakeFacturapi(create: [429, ['message' => 'demasiadas']])],
    'sin respuesta' => [fn () => fakeFacturapi(create: fn () => throw new ConnectionException('timeout'))],
    'existente aún pendiente' => [fn () => fakeFacturapi(existing: facturapiInvoice('pending', ['uuid' => null]))],
]);

it('rechazos definitivos: 400 con el mensaje de Facturapi, duplicado, total distinto o cancelada', function (Closure $scenario, string $code): void {
    $scenario();

    $rejection = null;
    try {
        facturapi()->stamp(facturapiDraft(), 'k1');
    } catch (CfdiRejectedException $exception) {
        $rejection = $exception;
    }

    expect($rejection?->errorCode)->toBe($code);
})->with([
    '400' => [fn () => fakeFacturapi(create: [400, ['message' => 'El RFC del receptor no está en la lista de RFC inscritos', 'code' => 'CFDI40145']]), 'CFDI40145'],
    'total distinto' => [fn () => fakeFacturapi(existing: facturapiInvoice('valid', ['total' => 99])), 'facturapi_mismatch'],
    'cancelada' => [fn () => fakeFacturapi(existing: facturapiInvoice('canceled')), 'facturapi_canceled'],
    'duplicada' => [function () {
        Http::fake(['*' => Http::response(['data' => [facturapiInvoice(), facturapiInvoice('valid', ['id' => 'inv_456'])]])]);
    }, 'facturapi_duplicate'],
]);

it('cancelación: motivo y sustitución por query; mapea cancelada, en espera, rechazada y no registrada', function (array $invoice, string $outcome): void {
    Http::fake(['*' => Http::response(facturapiInvoice(...$invoice))]);

    expect(facturapi()->cancel('UUID', 'inv_123', CfdiCancellationMotive::ErrorsWithRelation, 'UUID-NUEVO')->outcome)->toBe($outcome);
    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
        && str_contains($request->url(), '/invoices/inv_123?motive=01&substitution=UUID-NUEVO'));
})->with([
    [['canceled'], CancellationResult::CANCELLED],
    [['valid', ['cancellation_status' => 'pending']], CancellationResult::PENDING_ACCEPTANCE],
    [['valid', ['cancellation_status' => 'rejected']], CancellationResult::REJECTED],
    [['valid', ['cancellation_status' => 'none']], CancellationResult::NOT_REQUESTED],
]);

it('cancelación no permitida (409) es un rechazo definitivo', function (): void {
    Http::fake(['*' => Http::response(['message' => 'La factura tiene documentos relacionados vigentes'], 409)]);

    expect(fn () => facturapi()->cancel('UUID', 'inv_123', CfdiCancellationMotive::ErrorsWithoutRelation, null))
        ->toThrow(CfdiRejectedException::class, 'documentos relacionados');
});

it('registro: llave de prueba en cualquier entorno; llave real solo en producción; sin llave, sin PAC', function (): void {
    config(['cfdi.provider' => 'facturapi']);
    $registry = new CfdiProviderRegistry(app());

    expect($registry->isConfigured())->toBeTrue()->and($registry->current()->isTestMode())->toBeTrue();

    config(['cfdi.facturapi.key' => 'sk_live_ficticia']);
    expect((new CfdiProviderRegistry(app()))->isConfigured())->toBeFalse();

    config(['cfdi.facturapi.key' => null]);
    expect((new CfdiProviderRegistry(app()))->isConfigured())->toBeFalse();
});

it('de punta a punta con Facturapi simulado: el CFDI queda timbrado y un reintento adopta la factura existente', function (): void {
    config(['cfdi.provider' => 'facturapi']);
    app(CfdiProviderRegistry::class)->flush();
    OrganizationSetting::current()->forceFill([
        'legal_name' => 'FUNDACION DE PRUEBA', 'rfc' => 'FPR010101AAA', 'tax_regime' => '603', 'tax_postal_code' => '62000',
        'authorization_number' => '600-04-02-2026-0001', 'authorization_date' => '2026-01-15', 'donation_legend' => BuildDonationCfdiDraft::DONATARIA_LEGEND,
    ])->save();
    $donation = Donation::factory()->confirmed()->create(['donor_id' => Donor::factory()->withTaxProfile()->create()->id, 'manual_payment_method' => 'cash', 'amount' => '1500.50']);

    // Facturapi timbra la factura pero la respuesta se pierde (timeout).
    $created = null;
    $posts = 0;
    Http::fake(function (Request $request) use (&$created, &$posts) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        if ($request->method() === 'POST') {
            $posts++;
            $created = facturapiInvoice('valid', ['external_id' => $request->data()['external_id']]);
            throw new ConnectionException('timeout');
        }

        return match (true) {
            $path === '/v2/invoices' => Http::response(['data' => $created !== null ? [$created] : []]),
            str_ends_with($path, '/xml') => Http::response('<cfdi:Comprobante Version="4.0"/>'),
            default => Http::response('%PDF-1.7 prueba'),
        };
    });
    config(['queue.default' => 'database', 'cfdi.stamping.tries' => 1]);

    $cfdi = app(RequestDonationCfdi::class)->handle($donation, userWithRole(Role::Accountant));
    runQueueWorker();
    expect($cfdi->refresh()->status)->toBe(CfdiStatus::Failed)->and($cfdi->provider)->toBe('facturapi');

    // El reintento la encuentra por external_id y no crea otra.
    dispatch_sync(new StampCfdi($cfdi->id));

    expect($cfdi->refresh()->status)->toBe(CfdiStatus::Stamped)->and($cfdi->external_id)->toBe('inv_123')
        ->and(strtoupper((string) $cfdi->uuid))->toBe('A1B2C3D4-0000-4000-8000-000000000001')->and($posts)->toBe(1);
    Storage::disk('local')->assertExists([(string) $cfdi->xml_path, (string) $cfdi->pdf_path]);
    app(CfdiProviderRegistry::class)->flush();
});
