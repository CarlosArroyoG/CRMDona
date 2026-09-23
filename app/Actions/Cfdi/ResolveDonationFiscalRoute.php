<?php

declare(strict_types=1);

namespace App\Actions\Cfdi;

use App\Cfdi\Data\FiscalCoverage;
use App\Cfdi\Exceptions\CfdiNotReadyException;
use App\Enums\FiscalRoute;
use App\Models\Donation;

/**
 * Decide cómo cumple la organización su obligación de expedir CFDI por un
 * donativo confirmado. Todo donativo confirmado queda en una de tres rutas:
 *
 * 1. Individual: el donante tiene datos fiscales y el CFDI se puede armar.
 * 2. Público en general: sin datos fiscales (o con RFC genérico) → factura
 *    global (con Complemento Donatarias [V]). [F] Aún no habilitada:
 *    periodicidad y comprobantes de operación (docs/tecnico/fase-3-cfdi.md).
 * 3. Bloqueado: especie, reembolso, disputa, forma de pago sin resolver o
 *    datos por corregir.
 *
 * Que el donante haya pedido comprobante (`tax_receipt_requested`) NO
 * interviene: es información para la atención al donante.
 */
class ResolveDonationFiscalRoute
{
    public function __construct(private readonly BuildDonationCfdiDraft $draft) {}

    public function handle(Donation $donation): FiscalCoverage
    {
        $blocking = $this->draft->blockingReasons($donation);
        if ($blocking !== []) {
            return new FiscalCoverage(FiscalRoute::Blocked, $blocking);
        }

        if ($this->draft->isPublicGeneral($donation)) {
            return new FiscalCoverage(FiscalRoute::PublicGeneral, [
                '[F] La factura global de donativos al público en general aún no está habilitada: falta decidir periodicidad y comprobantes de operación.',
            ]);
        }

        try {
            $this->draft->handle($donation);
        } catch (CfdiNotReadyException $exception) {
            return new FiscalCoverage(FiscalRoute::Blocked, $exception->reasons);
        }

        return new FiscalCoverage(FiscalRoute::Individual);
    }
}
