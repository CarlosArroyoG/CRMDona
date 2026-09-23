<?php

declare(strict_types=1);

namespace App\Cfdi\Contracts;

use App\Cfdi\Data\CancellationResult;
use App\Cfdi\Data\CfdiDraft;
use App\Cfdi\Data\StampResult;
use App\Cfdi\Exceptions\CfdiProviderUnavailableException;
use App\Cfdi\Exceptions\CfdiRejectedException;
use App\Enums\CfdiCancellationMotive;

/**
 * Contrato de un PAC (Fase 3). El dominio nunca usa el SDK o la API de un
 * PAC directamente. Capacidades opcionales: RendersCfdiPdf.
 */
interface CfdiProvider
{
    public function name(): string;

    public function isTestMode(): bool;

    /**
     * Timbra el CFDI. Idempotente por `$idempotencyKey`: repetir la llamada
     * tras un timeout devuelve el mismo folio fiscal, no uno nuevo.
     *
     * @throws CfdiProviderUnavailableException
     * @throws CfdiRejectedException
     */
    public function stamp(CfdiDraft $draft, string $idempotencyKey): StampResult;

    /**
     * @throws CfdiProviderUnavailableException
     * @throws CfdiRejectedException
     */
    public function cancel(string $uuid, ?string $externalId, CfdiCancellationMotive $motive, ?string $replacementUuid): CancellationResult;

    /**
     * Estado actual de una cancelación en espera de aceptación.
     *
     * @throws CfdiProviderUnavailableException
     */
    public function cancellationStatus(string $uuid, ?string $externalId): CancellationResult;
}
