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
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * PAC simulado y determinista (solo local y testing). El XML que genera
 * lleva los datos del borrador pero NO tiene sello ni validez fiscal: lo
 * indica en el propio documento. Idempotente por llave, como un PAC real.
 * Los métodos willStamp()/willCancel() son controles para las pruebas.
 */
final class FakeCfdiProvider implements CfdiProvider, RendersCfdiPdf
{
    public const string STAMP_OK = 'ok';

    public const string STAMP_UNAVAILABLE = 'unavailable';

    public const string STAMP_TIMEOUT_AFTER = 'timeout_after_stamp';

    public const string STAMP_REJECTED = 'rejected';

    private const string KEY = 'fake-cfdi:state';

    public function name(): string
    {
        return 'fake';
    }

    public function isTestMode(): bool
    {
        return true;
    }

    public function willStamp(string ...$outcomes): self
    {
        $state = $this->state();
        $state['stamp'] = [...$state['stamp'], ...array_values($outcomes)];
        $this->save($state);

        return $this;
    }

    public function willCancel(string ...$outcomes): self
    {
        $state = $this->state();
        $state['cancel'] = [...$state['cancel'], ...array_values($outcomes)];
        $this->save($state);

        return $this;
    }

    public function stampedCount(): int
    {
        return count($this->state()['stamped']);
    }

    public function stamp(CfdiDraft $draft, string $idempotencyKey): StampResult
    {
        $state = $this->state();
        $outcome = array_shift($state['stamp']) ?? self::STAMP_OK;
        $this->save($state);

        if ($outcome === self::STAMP_UNAVAILABLE) {
            throw new CfdiProviderUnavailableException('El PAC simulado no está disponible.');
        }

        if ($outcome === self::STAMP_REJECTED) {
            throw new CfdiRejectedException('El PAC simulado rechazó el CFDI: RFC del receptor no válido.', 'CFDI40145');
        }

        $uuid = $state['stamped'][$idempotencyKey] ?? strtoupper((string) Str::uuid());
        $state['stamped'][$idempotencyKey] = $uuid;
        $this->save($state);

        if ($outcome === self::STAMP_TIMEOUT_AFTER) {
            throw new CfdiProviderUnavailableException('El PAC simulado no respondió a tiempo.');
        }

        return new StampResult(
            uuid: $uuid,
            xml: $this->xml($draft, $uuid),
            stampedAt: CarbonImmutable::now(),
            externalId: 'fake_cfdi_'.strtolower(substr($uuid, 0, 8)),
            pdf: "%PDF-1.4\n% CFDI simulado {$uuid}: sin validez fiscal\n",
        );
    }

    public function cancel(string $uuid, ?string $externalId, CfdiCancellationMotive $motive, ?string $replacementUuid): CancellationResult
    {
        // [V SAT] El motivo 01 exige el folio fiscal que sustituye.
        if ($motive->requiresReplacement() && $replacementUuid === null) {
            throw new CfdiRejectedException('El motivo 01 requiere el folio fiscal del CFDI que sustituye.', 'motive_01_without_replacement');
        }

        $state = $this->state();
        $outcome = array_shift($state['cancel']) ?? CancellationResult::CANCELLED;
        $this->save($state);

        if ($outcome === self::STAMP_UNAVAILABLE) {
            throw new CfdiProviderUnavailableException('El PAC simulado no está disponible.');
        }

        return new CancellationResult($outcome, "fake:{$outcome}");
    }

    public function cancellationStatus(string $uuid, ?string $externalId): CancellationResult
    {
        return $this->cancel($uuid, $externalId, CfdiCancellationMotive::ErrorsWithoutRelation, null);
    }

    public function pdf(string $uuid, ?string $externalId): string
    {
        return "%PDF-1.4\n% CFDI simulado {$uuid}: sin validez fiscal\n";
    }

    public function reset(): void
    {
        $this->cache()->forget(self::KEY);
    }

    private function xml(CfdiDraft $draft, string $uuid): string
    {
        $e = fn (string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $related = $draft->relatedUuids === [] ? '' : '<cfdi:CfdiRelacionados TipoRelacion="'.$e((string) $draft->relationType).'">'
            .implode('', array_map(fn (string $uuid): string => '<cfdi:CfdiRelacionado UUID="'.$e($uuid).'"/>', $draft->relatedUuids))
            .'</cfdi:CfdiRelacionados>';

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <!-- CFDI SIMULADO (FakeCfdiProvider): sin sello ni validez fiscal. -->
            <cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4" xmlns:donat="http://www.sat.gob.mx/donat" Version="4.0"
                Serie="{$e($draft->series)}" Folio="{$e($draft->folio)}" TipoDeComprobante="{$e($draft->voucherType)}"
                FormaPago="{$e($draft->paymentForm)}" MetodoPago="{$e($draft->paymentMethod)}" Moneda="{$e($draft->currency)}"
                SubTotal="{$e($draft->total)}" Total="{$e($draft->total)}" LugarExpedicion="{$e($draft->expeditionPostalCode)}">
              {$related}
              <cfdi:Emisor Rfc="{$e($draft->issuerRfc)}" Nombre="{$e($draft->issuerName)}" RegimenFiscal="{$e($draft->issuerRegime)}"/>
              <cfdi:Receptor Rfc="{$e($draft->receiverRfc)}" Nombre="{$e($draft->receiverName)}" RegimenFiscalReceptor="{$e($draft->receiverRegime)}"
                DomicilioFiscalReceptor="{$e($draft->receiverPostalCode)}" UsoCFDI="{$e($draft->cfdiUse)}"/>
              <cfdi:Conceptos>
                <cfdi:Concepto ClaveProdServ="{$e($draft->productCode)}" Cantidad="{$e($draft->quantity)}" ClaveUnidad="{$e($draft->unitCode)}"
                  Descripcion="{$e($draft->description)}" ValorUnitario="{$e($draft->unitValue)}" Importe="{$e($draft->total)}" ObjetoImp="{$e($draft->taxObject)}"/>
              </cfdi:Conceptos>
              <cfdi:Complemento>
                <donat:Donatarias version="1.1" noAutorizacion="{$e($draft->authorizationNumber)}" fechaAutorizacion="{$e($draft->authorizationDate)}" leyenda="{$e($draft->legend)}"/>
                <tfd:TimbreFiscalDigital xmlns:tfd="http://www.sat.gob.mx/TimbreFiscalDigital" UUID="{$uuid}" SelloSAT="SIMULADO"/>
              </cfdi:Complemento>
            </cfdi:Comprobante>
            XML;
    }

    /**
     * @return array{stamp: list<string>, cancel: list<string>, stamped: array<string, string>}
     */
    private function state(): array
    {
        /** @var array{stamp: list<string>, cancel: list<string>, stamped: array<string, string>}|null $state */
        $state = $this->cache()->get(self::KEY);

        return $state ?? ['stamp' => [], 'cancel' => [], 'stamped' => []];
    }

    /**
     * @param  array{stamp: list<string>, cancel: list<string>, stamped: array<string, string>}  $state
     */
    private function save(array $state): void
    {
        $this->cache()->forever(self::KEY, $state);
    }

    private function cache(): Repository
    {
        return Cache::store(config()->string('cfdi.fake.store'));
    }
}
