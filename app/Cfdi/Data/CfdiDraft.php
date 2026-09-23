<?php

declare(strict_types=1);

namespace App\Cfdi\Data;

/**
 * Contenido fiscal de un CFDI de donativo, independiente del PAC. Lo arma
 * BuildDonationCfdiDraft con las reglas verificadas del SAT; cada adaptador
 * lo traduce a su API. Los montos son strings decimales exactos.
 */
final readonly class CfdiDraft
{
    public function __construct(
        public int $donationId,
        public string $series,
        public string $folio,
        public string $issuerRfc,
        public string $issuerName,
        public string $issuerRegime,
        public string $expeditionPostalCode,
        public string $receiverRfc,
        public string $receiverName,
        public string $receiverRegime,
        public string $receiverPostalCode,
        public string $cfdiUse,
        public string $paymentForm,
        public string $paymentMethod,
        public string $voucherType,
        public string $currency,
        public string $productCode,
        public string $unitCode,
        public string $description,
        public string $quantity,
        public string $unitValue,
        public string $total,
        public string $taxObject,
        public string $authorizationNumber,
        public string $authorizationDate,
        public string $legend,
    ) {}
}
