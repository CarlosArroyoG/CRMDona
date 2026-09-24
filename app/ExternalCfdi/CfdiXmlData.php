<?php

declare(strict_types=1);

namespace App\ExternalCfdi;

use Carbon\CarbonImmutable;

/**
 * Datos leídos del XML de un CFDI externo.
 */
final readonly class CfdiXmlData
{
    public function __construct(
        public string $uuid,
        public string $version,
        public CarbonImmutable $issuedAt,
        public ?CarbonImmutable $stampedAt,
        public ?string $total,
        public ?string $issuerRfc,
    ) {}
}
