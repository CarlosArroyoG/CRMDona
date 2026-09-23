<?php

declare(strict_types=1);

namespace App\Cfdi\Providers;

use App\Cfdi\Contracts\CfdiProvider;
use App\Cfdi\Contracts\RendersCfdiPdf;
use App\Cfdi\Data\CancellationResult;
use App\Cfdi\Data\CfdiDraft;
use App\Cfdi\Data\StampResult;
use App\Cfdi\Exceptions\CfdiProviderUnavailableException;
use App\Cfdi\Exceptions\CfdiRejectedException;
use App\Enums\CfdiCancellationMotive;
use App\Support\SensitiveData;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Facturapi (API v2) con el cliente HTTP de Laravel; sin SDK.
 * Referencia: https://docs.facturapi.io/api/ (especificación api-es.yaml).
 *
 * Idempotencia [S]: Facturapi documenta `idempotency_key` ("evitar
 * duplicados al reintentar"), pero no qué responde ante una llave repetida.
 * Por eso, antes de cada timbrado se busca la factura por `external_id` (=
 * nuestra llave). Si ya existe, se adopta en lugar de crear otra. Así, un
 * timeout, un 5xx o un 202 (rescate por intermitencia, hasta 50 minutos)
 * nunca se reintenta a ciegas.
 *
 * El emisor (RFC, régimen, CP y CSD) es la organización configurada en el
 * panel de Facturapi: debe coincidir con Administración → Organización.
 */
final class FacturapiCfdiProvider implements CfdiProvider, RendersCfdiPdf
{
    public const string DONAT_NAMESPACE = 'http://www.sat.gob.mx/donat';

    public const string DONAT_SCHEMA = 'http://www.sat.gob.mx/sitio_internet/cfd/donat/donat11.xsd';

    public function __construct(private readonly string $key) {}

    public function name(): string
    {
        return 'facturapi';
    }

    public function isTestMode(): bool
    {
        return str_starts_with($this->key, 'sk_test_');
    }

    public function stamp(CfdiDraft $draft, string $idempotencyKey): StampResult
    {
        $invoice = $this->findByExternalId($idempotencyKey)
            ?? $this->decode($this->send(fn (PendingRequest $http) => $http->post('invoices', $this->payload($draft, $idempotencyKey))));

        return $this->stampResult($invoice, $draft);
    }

    public function cancel(string $uuid, ?string $externalId, CfdiCancellationMotive $motive, ?string $replacementUuid): CancellationResult
    {
        $query = array_filter(['motive' => $motive->value, 'substitution' => $replacementUuid]);

        return $this->cancellationResult($this->decode($this->send(
            fn (PendingRequest $http) => $http->delete('invoices/'.rawurlencode($this->requireId($externalId)).'?'.http_build_query($query)),
            definitive: [400, 404, 409],
        )));
    }

    public function cancellationStatus(string $uuid, ?string $externalId): CancellationResult
    {
        return $this->cancellationResult($this->decode($this->send(
            fn (PendingRequest $http) => $http->get('invoices/'.rawurlencode($this->requireId($externalId))),
        )));
    }

    public function pdf(string $uuid, ?string $externalId): string
    {
        return $this->download($this->requireId($externalId), 'pdf');
    }

    /**
     * Cuerpo de "Crear factura" (CFDI 4.0, tipo I). El complemento de
     * donatarias va como complemento `custom` (nodo XML propio); Facturapi no
     * lo imprime en el PDF, por eso se agrega en `pdf_custom_section`.
     *
     * Los montos viajan como número JSON solo en esta frontera: se parten de
     * strings decimales de 2 posiciones, que un float representa sin pérdida
     * al serializarse (serialize_precision = -1).
     *
     * @return array<string, mixed>
     */
    public function payload(CfdiDraft $draft, string $idempotencyKey): array
    {
        $payload = [
            'type' => $draft->voucherType,
            'customer' => [
                'legal_name' => $draft->receiverName,
                'tax_id' => $draft->receiverRfc,
                'tax_system' => $draft->receiverRegime,
                'address' => ['zip' => $draft->receiverPostalCode],
            ],
            'items' => [[
                'quantity' => (float) $draft->quantity,
                'product' => [
                    'description' => $draft->description,
                    'product_key' => $draft->productCode,
                    'unit_key' => $draft->unitCode,
                    'unit_name' => 'Valor monetario',
                    'price' => (float) $draft->unitValue,
                    'tax_included' => false,
                    'taxability' => $draft->taxObject,
                    'taxes' => [],
                ],
            ]],
            'payment_form' => $draft->paymentForm,
            'payment_method' => $draft->paymentMethod,
            'use' => $draft->cfdiUse,
            'currency' => $draft->currency,
            'series' => $draft->series,
            'folio_number' => (int) $draft->folio,
            'external_id' => $idempotencyKey,
            'idempotency_key' => $idempotencyKey,
            'complements' => [['type' => 'custom', 'data' => $this->donatariasXml($draft)]],
            'namespaces' => [['prefix' => 'donat', 'uri' => self::DONAT_NAMESPACE, 'schema_location' => self::DONAT_SCHEMA]],
            'pdf_custom_section' => $this->pdfSection($draft),
        ];

        if ($draft->relatedUuids !== []) {
            $payload['related_documents'] = [['relationship' => $draft->relationType, 'documents' => $draft->relatedUuids]];
        }

        return $payload;
    }

    /**
     * [V] Complemento Donatarias 1.1 (donat11.xsd): version, noAutorizacion,
     * fechaAutorizacion y leyenda.
     */
    public function donatariasXml(CfdiDraft $draft): string
    {
        $e = fn (string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return '<donat:Donatarias xmlns:donat="'.self::DONAT_NAMESPACE.'" version="1.1"'
            .' noAutorizacion="'.$e($draft->authorizationNumber).'"'
            .' fechaAutorizacion="'.$e($draft->authorizationDate).'"'
            .' leyenda="'.$e($draft->legend).'"/>';
    }

    private function pdfSection(CfdiDraft $draft): string
    {
        $e = fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return '<p><b>Donataria autorizada.</b> Oficio de autorización '.$e($draft->authorizationNumber)
            .' de fecha '.$e($draft->authorizationDate).'.</p><p>'.$e($draft->legend).'</p>';
    }

    /**
     * Reconciliación previa: la factura que ya se creó con esta llave.
     *
     * @return array<string, mixed>|null
     */
    private function findByExternalId(string $key): ?array
    {
        $found = $this->decode($this->send(fn (PendingRequest $http) => $http->get('invoices', ['external_id' => $key, 'limit' => 10])));
        /** @var list<array<string, mixed>> $data */
        $data = is_array($found['data'] ?? null) ? array_values($found['data']) : [];
        $data = array_values(array_filter($data, fn (array $invoice): bool => ($invoice['external_id'] ?? null) === $key
            && in_array($invoice['status'] ?? null, ['valid', 'pending', 'canceled'], true)));

        if (count($data) > 1) {
            throw new CfdiRejectedException('Facturapi tiene más de una factura con la misma llave; revisar en su panel antes de continuar.', 'facturapi_duplicate');
        }

        return $data[0] ?? null;
    }

    /**
     * @param  array<string, mixed>  $invoice
     */
    private function stampResult(array $invoice, CfdiDraft $draft): StampResult
    {
        $status = $invoice['status'] ?? null;

        if ($status === 'pending') {
            throw new CfdiProviderUnavailableException('Facturapi aún no confirma el timbrado (rescate por intermitencia del SAT); se consultará de nuevo.');
        }

        if ($status === 'canceled') {
            throw new CfdiRejectedException('La factura creada con esta llave está cancelada en Facturapi; revisar antes de reintentar.', 'facturapi_canceled');
        }

        $id = is_string($invoice['id'] ?? null) ? $invoice['id'] : null;
        $uuid = is_string($invoice['uuid'] ?? null) ? strtoupper($invoice['uuid']) : null;
        if ($status !== 'valid' || $id === null || $uuid === null) {
            throw new CfdiProviderUnavailableException('Facturapi respondió sin folio fiscal; se consultará de nuevo.');
        }

        $total = is_numeric($invoice['total'] ?? null) ? number_format((float) $invoice['total'], 2, '.', '') : null;
        if ($total !== null && $total !== $draft->total) {
            throw new CfdiRejectedException("La factura en Facturapi tiene un total distinto ({$total}); revisar antes de continuar.", 'facturapi_mismatch');
        }

        /** @var array<string, mixed> $stamp */
        $stamp = is_array($invoice['stamp'] ?? null) ? $invoice['stamp'] : [];
        $date = is_string($stamp['date'] ?? null) ? $stamp['date'] : (is_string($invoice['date'] ?? null) ? $invoice['date'] : null);

        return new StampResult(
            uuid: $uuid,
            xml: $this->download($id, 'xml'),
            stampedAt: $date !== null ? CarbonImmutable::parse($date) : CarbonImmutable::now(),
            externalId: $id,
            pdf: $this->download($id, 'pdf'),
        );
    }

    /**
     * `status: canceled` → cancelado. `valid` con `cancellation_status:
     * pending` → en espera del receptor. `valid` con `none` → la solicitud no
     * quedó registrada (se reenvía). `valid` con otro valor (rechazada o vencida
     * [S]) → sigue vigente.
     *
     * @param  array<string, mixed>  $invoice
     */
    private function cancellationResult(array $invoice): CancellationResult
    {
        $status = is_string($invoice['status'] ?? null) ? $invoice['status'] : 'unknown';
        $cancellation = is_string($invoice['cancellation_status'] ?? null) ? $invoice['cancellation_status'] : 'none';
        $providerStatus = "{$status}/{$cancellation}";

        return match (true) {
            $status === 'canceled' => new CancellationResult(CancellationResult::CANCELLED, $providerStatus),
            $cancellation === 'pending' => new CancellationResult(CancellationResult::PENDING_ACCEPTANCE, $providerStatus),
            $cancellation === 'none' => new CancellationResult(CancellationResult::NOT_REQUESTED, $providerStatus),
            default => new CancellationResult(CancellationResult::REJECTED, $providerStatus),
        };
    }

    private function download(string $id, string $format): string
    {
        $response = $this->send(fn (PendingRequest $http) => $http->accept('*/*')->get('invoices/'.rawurlencode($id).'/'.$format));
        $body = $response->body();

        if ($body === '') {
            throw new CfdiProviderUnavailableException("Facturapi no entregó el {$format}; se reintentará.");
        }

        return $body;
    }

    /**
     * `$definitive` (400 y 404; en cancelación también 409, "no cancelable")
     * → rechazo definitivo. 401, 429, 5xx, 202, sin respuesta y el 409 al crear
     * → no disponible: el resultado es incierto o temporal y el reintento
     * empieza por buscar la factura.
     *
     * @param  callable(PendingRequest): Response  $call
     * @param  list<int>  $definitive
     */
    private function send(callable $call, array $definitive = [400, 404]): Response
    {
        try {
            $response = $call($this->http());
        } catch (ConnectionException) {
            throw new CfdiProviderUnavailableException('Facturapi no respondió; se consultará antes de reintentar.');
        }

        if ($response->status() === 202) {
            throw new CfdiProviderUnavailableException('Facturapi aceptó la factura pero el SAT no la ha confirmado (rescate por intermitencia).');
        }

        if ($response->successful()) {
            return $response;
        }

        $message = SensitiveData::safeText(is_string($response->json('message')) ? $response->json('message') : null) ?? 'sin detalle';
        $code = is_string($response->json('code')) ? $response->json('code') : 'facturapi_'.$response->status();

        if (in_array($response->status(), $definitive, true)) {
            throw new CfdiRejectedException("Facturapi rechazó la solicitud: {$message}", $code);
        }

        throw new CfdiProviderUnavailableException("Facturapi respondió {$response->status()}: {$message}");
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        /** @var array<string, mixed>|null $json */
        $json = $response->json();

        return is_array($json) ? $json : throw new CfdiProviderUnavailableException('Facturapi respondió sin JSON.');
    }

    private function requireId(?string $externalId): string
    {
        return $externalId ?? throw new CfdiRejectedException('El CFDI no tiene el identificador de Facturapi.', 'facturapi_missing_id');
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim(config()->string('cfdi.facturapi.base_url'), '/').'/')
            ->withToken($this->key)
            ->acceptJson()
            ->timeout(config()->integer('cfdi.facturapi.timeout'))
            ->connectTimeout(config()->integer('cfdi.facturapi.connect_timeout'));
    }
}
